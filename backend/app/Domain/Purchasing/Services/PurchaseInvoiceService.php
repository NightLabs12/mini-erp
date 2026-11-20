<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Domain\Accounting\Models\Journal;
use App\Domain\Accounting\Services\AccountMapper;
use App\Domain\Accounting\Services\JournalService;
use App\Domain\Accounting\Services\PeriodGuard;
use App\Domain\Core\Services\AuditTrailService;
use App\Domain\Core\Services\DocumentSequenceService;
use App\Domain\Core\Services\SettingService;
use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Supplier;
use App\Domain\Master\Models\Unit;
use App\Domain\Master\Services\UnitConversionService;
use App\Domain\Purchasing\Models\GoodsReceiptItem;
use App\Domain\Purchasing\Models\PurchaseInvoice;
use App\Domain\Purchasing\Models\PurchaseInvoiceItem;
use App\Domain\Purchasing\Models\PurchaseOrderItem;
use App\Exceptions\ApiException;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Invoice dari satu atau beberapa Goods Receipt.
 *
 * Jurnal approve (SPEC 3.8):
 * - Dr GR/IR sebesar nilai GR yang ditagih,
 * - Dr PPN Masukan,
 * - Dr/Cr Selisih Harga Pembelian bila harga invoice berbeda,
 * - Cr Hutang Usaha sebesar total invoice.
 */
final class PurchaseInvoiceService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly PeriodGuard $periods,
        private readonly UnitConversionService $units,
        private readonly JournalService $journals,
        private readonly AccountMapper $accounts,
        private readonly SettingService $settings,
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($data): PurchaseInvoice {
            $day = $this->day($data['doc_date']);
            $period = $this->periods->assertOpen($day);
            $supplierId = (int) $data['supplier_id'];
            /** @var Supplier $supplier */
            $supplier = Supplier::query()->findOrFail($supplierId);

            /** @var PurchaseInvoice $invoice */
            $invoice = PurchaseInvoice::query()->create([
                'doc_no' => $this->sequences->next('PI', $day),
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'supplier_id' => $supplierId,
                'due_date' => $day->addDays((int) $supplier->payment_term_days)->toDateString(),
                'status' => PurchaseInvoice::STATUS_DRAFT,
                'tax_rate' => $this->taxRate($data['tax_rate'] ?? null),
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->actorId(),
            ]);

            $this->syncItems($invoice, $supplierId, $data['items'] ?? []);
            $this->recalculate($invoice);

            return $this->load($invoice);
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PurchaseInvoice $invoice, array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $data): PurchaseInvoice {
            $locked = $this->lock($invoice);

            if ($locked->status !== PurchaseInvoice::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya purchase invoice draft yang boleh diubah.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $day = array_key_exists('doc_date', $data)
                ? $this->day($data['doc_date'])
                : $this->day($locked->doc_date);
            $period = $this->periods->assertOpen($day);
            $supplierId = (int) ($data['supplier_id'] ?? $locked->supplier_id);
            /** @var Supplier $supplier */
            $supplier = Supplier::query()->findOrFail($supplierId);

            $locked->forceFill([
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'supplier_id' => $supplierId,
                'due_date' => $day->addDays((int) $supplier->payment_term_days)->toDateString(),
                'tax_rate' => array_key_exists('tax_rate', $data)
                    ? $this->taxRate($data['tax_rate'])
                    : (string) $locked->tax_rate,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
            ])->save();

            if (array_key_exists('items', $data)) {
                $this->syncItems($locked, $supplierId, $data['items']);
            }

            $this->recalculate($locked);

            return $this->load($locked);
        }, 5);
    }

    public function deleteDraft(PurchaseInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $locked = $this->lock($invoice);

            if ($locked->status !== PurchaseInvoice::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya purchase invoice draft yang boleh dihapus.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $locked->items()->delete();
            $locked->delete();
        }, 5);
    }

    public function approve(PurchaseInvoice $invoice): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice): PurchaseInvoice {
            $locked = $this->lock($invoice);

            if ($locked->status === PurchaseInvoice::STATUS_APPROVED) {
                return $this->load($locked);
            }

            if ($locked->status !== PurchaseInvoice::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Purchase invoice tidak dapat disetujui dari status ini.',
                    'DOCUMENT_STATE_INVALID',
                );
            }

            $day = $this->day($locked->doc_date);
            $this->periods->assertOpen($day, true);

            /** @var Collection<int, PurchaseInvoiceItem> $items */
            $items = $locked->items()
                ->with(['goodsReceiptItem.receipt', 'goodsReceiptItem.purchaseOrderItem', 'item', 'unit'])
                ->orderBy('goods_receipt_item_id')
                ->orderBy('id')
                ->get();

            if ($items->isEmpty()) {
                throw ApiException::unprocessable(
                    'Purchase invoice tidak memiliki baris item.',
                    'DOCUMENT_EMPTY',
                );
            }

            $grItemIds = $items->pluck('goods_receipt_item_id')->map(static fn ($id): int => (int) $id)->all();

            /** @var Collection<int, GoodsReceiptItem> $grItems */
            $grItems = GoodsReceiptItem::query()
                ->with('receipt')
                ->whereIn('id', $grItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (GoodsReceiptItem $item): int => (int) $item->getKey());

            $poItemIds = $grItems
                ->pluck('purchase_order_item_id')
                ->filter()
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            /** @var Collection<int, PurchaseOrderItem> $poItems */
            $poItems = PurchaseOrderItem::query()
                ->whereIn('id', $poItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (PurchaseOrderItem $item): int => (int) $item->getKey());

            foreach ($items as $item) {
                $grItem = $grItems->get((int) $item->goods_receipt_item_id);

                if (! $grItem instanceof GoodsReceiptItem) {
                    throw ApiException::unprocessable(
                        'Baris goods receipt tidak ditemukan.',
                        'PURCHASE_INVOICE_LINE_INVALID',
                    );
                }

                if ($grItem->receipt === null || $grItem->receipt->status !== 'approved') {
                    throw ApiException::conflict(
                        'Purchase invoice hanya dapat menagih goods receipt approved.',
                        'GOODS_RECEIPT_NOT_APPROVED',
                    );
                }

                if ((int) $grItem->receipt->supplier_id !== (int) $locked->supplier_id) {
                    throw ApiException::unprocessable(
                        'Supplier invoice tidak sesuai dengan supplier goods receipt.',
                        'PURCHASE_INVOICE_SUPPLIER_MISMATCH',
                    );
                }

                $remaining = Decimal::sub(
                    (string) $grItem->qty_base,
                    (string) $grItem->invoiced_qty_base,
                    Decimal::QTY_SCALE,
                );

                if (Decimal::compare((string) $item->qty_base, $remaining, Decimal::QTY_SCALE) > 0) {
                    throw ApiException::unprocessable(
                        "Qty invoice melebihi sisa goods receipt: sisa {$remaining}, diminta {$item->qty_base}.",
                        'PI_QTY_EXCEEDS_REMAINING',
                        ['items' => ["Sisa yang boleh ditagih {$remaining}."]],
                    );
                }

                $grItem->forceFill([
                    'invoiced_qty_base' => Decimal::add(
                        (string) $grItem->invoiced_qty_base,
                        (string) $item->qty_base,
                        Decimal::QTY_SCALE,
                    ),
                ])->save();

                $poItem = $grItem->purchase_order_item_id === null
                    ? null
                    : $poItems->get((int) $grItem->purchase_order_item_id);

                if ($poItem instanceof PurchaseOrderItem) {
                    $poItem->forceFill([
                        'invoiced_qty_base' => Decimal::add(
                            (string) $poItem->invoiced_qty_base,
                            (string) $item->qty_base,
                            Decimal::QTY_SCALE,
                        ),
                    ])->save();
                }
            }

            $journal = $this->postInvoiceJournal($locked, $items, $day);

            $locked->forceFill([
                'status' => PurchaseInvoice::STATUS_APPROVED,
                'journal_id' => $journal?->getKey(),
                'approved_by' => $this->actorId(),
                'approved_at' => now(),
            ])->save();

            $this->audit->record($locked, 'approved', ['status' => PurchaseInvoice::STATUS_DRAFT], [
                'status' => PurchaseInvoice::STATUS_APPROVED,
                'doc_no' => $locked->doc_no,
                'journal_id' => $locked->journal_id,
            ]);

            return $this->load($locked);
        }, 5);
    }

    public function disapprove(PurchaseInvoice $invoice): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice): PurchaseInvoice {
            $locked = $this->lock($invoice);

            if ($locked->status !== PurchaseInvoice::STATUS_APPROVED) {
                throw ApiException::conflict(
                    'Hanya purchase invoice approved yang dapat dibatalkan.',
                    'DOCUMENT_NOT_APPROVED',
                );
            }

            $day = $this->day($locked->doc_date);
            $this->periods->assertOpen($day, true);

            if (Decimal::compare((string) $locked->paid_amount, '0', Decimal::MONEY_SCALE) > 0
                || $locked->allocations()->exists()) {
                throw ApiException::conflict(
                    'Purchase invoice sudah memiliki pembayaran.',
                    'DOCUMENT_HAS_PAYMENTS',
                    ['references' => ['supplier_payments']],
                );
            }

            /** @var Collection<int, PurchaseInvoiceItem> $items */
            $items = $locked->items()->orderBy('goods_receipt_item_id')->orderBy('id')->get();

            $grItemIds = $items->pluck('goods_receipt_item_id')->map(static fn ($id): int => (int) $id)->all();

            /** @var Collection<int, GoodsReceiptItem> $grItems */
            $grItems = GoodsReceiptItem::query()
                ->with('receipt')
                ->whereIn('id', $grItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (GoodsReceiptItem $item): int => (int) $item->getKey());

            $poItemIds = $grItems
                ->pluck('purchase_order_item_id')
                ->filter()
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            /** @var Collection<int, PurchaseOrderItem> $poItems */
            $poItems = PurchaseOrderItem::query()
                ->whereIn('id', $poItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (PurchaseOrderItem $item): int => (int) $item->getKey());

            foreach ($items as $item) {
                $grItem = $grItems->get((int) $item->goods_receipt_item_id);

                if ($grItem instanceof GoodsReceiptItem) {
                    $grItem->forceFill([
                        'invoiced_qty_base' => $this->subtractFloor(
                            (string) $grItem->invoiced_qty_base,
                            (string) $item->qty_base,
                        ),
                    ])->save();

                    $poItem = $grItem->purchase_order_item_id === null
                        ? null
                        : $poItems->get((int) $grItem->purchase_order_item_id);

                    if ($poItem instanceof PurchaseOrderItem) {
                        $poItem->forceFill([
                            'invoiced_qty_base' => $this->subtractFloor(
                                (string) $poItem->invoiced_qty_base,
                                (string) $item->qty_base,
                            ),
                        ])->save();
                    }
                }
            }

            $journal = $locked->journal_id !== null
                ? Journal::query()->find($locked->journal_id)
                : null;

            if ($journal instanceof Journal && $journal->status === Journal::STATUS_POSTED) {
                $this->journals->reverse($journal);
            }

            $locked->forceFill([
                'status' => PurchaseInvoice::STATUS_DRAFT,
                'journal_id' => null,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            $this->audit->record($locked, 'disapproved', ['status' => PurchaseInvoice::STATUS_APPROVED], [
                'status' => PurchaseInvoice::STATUS_DRAFT,
                'doc_no' => $locked->doc_no,
            ]);

            return $this->load($locked);
        }, 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(PurchaseInvoice $invoice, int $supplierId, array $items): void
    {
        $invoice->items()->delete();

        $line = 1;

        foreach ($items as $row) {
            /** @var GoodsReceiptItem $grItem */
            $grItem = GoodsReceiptItem::query()
                ->with(['receipt', 'purchaseOrderItem', 'item', 'unit'])
                ->findOrFail((int) $row['goods_receipt_item_id']);

            if ($grItem->receipt === null || $grItem->receipt->status !== 'approved') {
                throw ApiException::conflict(
                    'Purchase invoice hanya dapat menagih goods receipt approved.',
                    'GOODS_RECEIPT_NOT_APPROVED',
                );
            }

            if ((int) $grItem->receipt->supplier_id !== $supplierId) {
                throw ApiException::unprocessable(
                    'Supplier invoice tidak sesuai dengan supplier goods receipt.',
                    'PURCHASE_INVOICE_SUPPLIER_MISMATCH',
                );
            }

            /** @var Item $item */
            $item = $grItem->item;
            /** @var Unit $unit */
            $unit = $grItem->unit;

            $qty = Decimal::round((string) $row['qty'], Decimal::QTY_SCALE);
            $qtyBase = $this->units->toBase($item, $unit, $qty);
            $remaining = Decimal::sub(
                (string) $grItem->qty_base,
                (string) $grItem->invoiced_qty_base,
                Decimal::QTY_SCALE,
            );

            if (Decimal::compare($qtyBase, $remaining, Decimal::QTY_SCALE) > 0) {
                throw ApiException::unprocessable(
                    "Qty invoice melebihi sisa goods receipt: sisa {$remaining}, diminta {$qtyBase}.",
                    'PI_QTY_EXCEEDS_REMAINING',
                    ['items' => ["Sisa yang boleh ditagih {$remaining}."]],
                );
            }

            $unitPrice = Decimal::round((string) $row['unit_price'], Decimal::QTY_SCALE);

            if (Decimal::isNegative($unitPrice)) {
                throw ApiException::unprocessable(
                    'Harga satuan invoice tidak boleh negatif.',
                    'PURCHASE_PRICE_INVALID',
                );
            }

            $unitPriceBase = $this->units->unitCostPerBase($item, $unit, $unitPrice);
            $grUnitCost = (string) $grItem->unit_cost_base;
            $grValue = Decimal::round(
                Decimal::mul($qtyBase, $grUnitCost, Decimal::QTY_SCALE + 2),
                Decimal::MONEY_SCALE,
            );
            $totalValue = Decimal::round(
                Decimal::mul($qtyBase, $unitPriceBase, Decimal::QTY_SCALE + 2),
                Decimal::MONEY_SCALE,
            );

            $invoice->items()->create([
                'goods_receipt_item_id' => $grItem->getKey(),
                'item_id' => $item->getKey(),
                'unit_id' => $unit->getKey(),
                'line_no' => $line++,
                'qty' => $qty,
                'qty_base' => $qtyBase,
                'unit_price' => $unitPrice,
                'unit_price_base' => $unitPriceBase,
                'gr_unit_cost' => $grUnitCost,
                'gr_value' => $grValue,
                'total_value' => $totalValue,
            ]);
        }
    }

    private function recalculate(PurchaseInvoice $invoice): void
    {
        $subtotal = '0.00';

        /** @var Collection<int, PurchaseInvoiceItem> $items */
        $items = $invoice->items()->get();

        foreach ($items as $item) {
            $subtotal = Decimal::add($subtotal, (string) $item->total_value, Decimal::MONEY_SCALE);
        }

        $taxRate = Decimal::round((string) $invoice->tax_rate, 2);
        $taxAmount = Decimal::round(
            Decimal::div(Decimal::mul($subtotal, $taxRate, 6), '100', 6),
            Decimal::MONEY_SCALE,
        );
        $total = Decimal::add($subtotal, $taxAmount, Decimal::MONEY_SCALE);

        $invoice->forceFill([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
        ])->save();
    }

    /**
     * @param  Collection<int, PurchaseInvoiceItem>  $items
     */
    private function postInvoiceJournal(
        PurchaseInvoice $invoice,
        Collection $items,
        CarbonImmutable $day,
    ): ?Journal {
        $grirTotal = '0.00';

        foreach ($items as $item) {
            $grirTotal = Decimal::add($grirTotal, (string) $item->gr_value, Decimal::MONEY_SCALE);
        }

        $subtotal = Decimal::round((string) $invoice->subtotal, Decimal::MONEY_SCALE);
        $vat = Decimal::round((string) $invoice->tax_amount, Decimal::MONEY_SCALE);
        $total = Decimal::round((string) $invoice->total, Decimal::MONEY_SCALE);
        $variance = Decimal::sub($subtotal, $grirTotal, Decimal::MONEY_SCALE);

        if (Decimal::compare($total, '0', Decimal::MONEY_SCALE) <= 0) {
            return null;
        }

        $lines = [];

        if (Decimal::compare($grirTotal, '0', Decimal::MONEY_SCALE) > 0) {
            $lines[] = [
                'account_id' => (int) $this->accounts->accountFor(AccountMapper::GRIR)->getKey(),
                'debit' => $grirTotal,
            ];
        }

        if (Decimal::compare($vat, '0', Decimal::MONEY_SCALE) > 0) {
            $lines[] = [
                'account_id' => (int) $this->accounts->accountFor(AccountMapper::VAT_IN)->getKey(),
                'debit' => $vat,
            ];
        }

        if (Decimal::compare($variance, '0', Decimal::MONEY_SCALE) > 0) {
            $lines[] = [
                'account_id' => (int) $this->accounts->accountFor(AccountMapper::PURCHASE_PRICE_VARIANCE)->getKey(),
                'debit' => $variance,
            ];
        } elseif (Decimal::compare($variance, '0', Decimal::MONEY_SCALE) < 0) {
            $lines[] = [
                'account_id' => (int) $this->accounts->accountFor(AccountMapper::PURCHASE_PRICE_VARIANCE)->getKey(),
                'credit' => Decimal::mul($variance, '-1', Decimal::MONEY_SCALE),
            ];
        }

        $lines[] = [
            'account_id' => (int) $this->accounts->accountFor(AccountMapper::ACCOUNTS_PAYABLE)->getKey(),
            'credit' => $total,
        ];

        if (count($lines) < 2) {
            return null;
        }

        return $this->journals->post(
            $lines,
            ['type' => 'purchase_invoice', 'id' => (int) $invoice->getKey()],
            $day,
            "Tagihan pembelian {$invoice->doc_no}",
        );
    }

    private function subtractFloor(string $current, string $amount): string
    {
        $result = Decimal::sub($current, $amount, Decimal::QTY_SCALE);

        return Decimal::compare($result, '0', Decimal::QTY_SCALE) < 0 ? '0.0000' : $result;
    }

    private function taxRate(mixed $value): string
    {
        $rate = $value === null || $value === ''
            ? $this->settings->decimal('tax_rate', '11')
            : (string) $value;

        $rate = Decimal::round($rate, 2);

        if (Decimal::isNegative($rate) || Decimal::compare($rate, '100', 2) > 0) {
            throw ApiException::unprocessable(
                'Tarif PPN harus antara 0 dan 100 persen.',
                'TAX_RATE_INVALID',
                ['tax_rate' => ['Tarif PPN harus antara 0 dan 100.']],
            );
        }

        return $rate;
    }

    private function lock(PurchaseInvoice $invoice): PurchaseInvoice
    {
        /** @var PurchaseInvoice $locked */
        $locked = PurchaseInvoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

        return $locked;
    }

    private function load(PurchaseInvoice $invoice): PurchaseInvoice
    {
        return $invoice->refresh()->load([
            'supplier',
            'journal',
            'items.item',
            'items.unit',
            'items.goodsReceiptItem',
        ]);
    }

    private function day(CarbonInterface|string $date): CarbonImmutable
    {
        return $date instanceof CarbonInterface
            ? CarbonImmutable::parse($date->toDateString())
            : CarbonImmutable::parse($date);
    }

    private function actorId(): ?int
    {
        $user = app()->bound('request') ? request()->user() : null;
        $id = $user?->getAuthIdentifier() ?? Auth::id();

        return $id === null ? null : (int) $id;
    }
}
