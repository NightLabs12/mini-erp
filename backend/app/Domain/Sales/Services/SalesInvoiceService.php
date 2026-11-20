<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Accounting\Models\Journal;
use App\Domain\Accounting\Services\AccountMapper;
use App\Domain\Accounting\Services\JournalService;
use App\Domain\Accounting\Services\PeriodGuard;
use App\Domain\Core\Services\AuditTrailService;
use App\Domain\Core\Services\DocumentSequenceService;
use App\Domain\Core\Services\SettingService;
use App\Domain\Master\Models\Customer;
use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Unit;
use App\Domain\Master\Services\UnitConversionService;
use App\Domain\Sales\Models\DeliveryOrderItem;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Models\SalesInvoiceItem;
use App\Domain\Sales\Models\SalesOrderItem;
use App\Exceptions\ApiException;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sales Invoice dari satu atau beberapa Delivery Order.
 *
 * Jurnal approve (SPEC 3.8): Dr Piutang Usaha / Cr Penjualan per kategori,
 * Cr PPN Keluaran. mode draft berarti masih dapat diubah; mode final berarti
 * sudah diposting dan terkunci.
 */
final class SalesInvoiceService
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
    public function create(array $data): SalesInvoice
    {
        $invoice = DB::transaction(function () use ($data): SalesInvoice {
            $day = $this->day($data['doc_date']);
            $period = $this->periods->assertOpen($day);
            $customerId = (int) $data['customer_id'];
            /** @var Customer $customer */
            $customer = Customer::query()->findOrFail($customerId);
            $mode = $this->mode($data['mode'] ?? null);

            /** @var SalesInvoice $invoice */
            $invoice = SalesInvoice::query()->create([
                'doc_no' => $this->sequences->next('SI', $day),
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'customer_id' => $customerId,
                'due_date' => $day->addDays((int) $customer->payment_term_days)->toDateString(),
                'status' => SalesInvoice::STATUS_DRAFT,
                'mode' => $mode,
                'tax_rate' => $this->taxRate($data['tax_rate'] ?? null),
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->actorId(),
            ]);

            $this->syncItems($invoice, $customerId, $data['items'] ?? []);
            $this->recalculate($invoice);

            return $this->load($invoice);
        }, 5);

        if ($invoice->mode === SalesInvoice::MODE_FINAL) {
            return $this->approve($invoice);
        }

        return $invoice;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SalesInvoice $invoice, array $data): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $data): SalesInvoice {
            $locked = $this->lock($invoice);

            if ($locked->status !== SalesInvoice::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya sales invoice draft yang boleh diubah.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $day = array_key_exists('doc_date', $data)
                ? $this->day($data['doc_date'])
                : $this->day($locked->doc_date);
            $period = $this->periods->assertOpen($day);
            $customerId = (int) ($data['customer_id'] ?? $locked->customer_id);
            /** @var Customer $customer */
            $customer = Customer::query()->findOrFail($customerId);

            $locked->forceFill([
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'customer_id' => $customerId,
                'due_date' => $day->addDays((int) $customer->payment_term_days)->toDateString(),
                'mode' => array_key_exists('mode', $data) ? $this->mode($data['mode']) : (string) $locked->mode,
                'tax_rate' => array_key_exists('tax_rate', $data)
                    ? $this->taxRate($data['tax_rate'])
                    : (string) $locked->tax_rate,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
            ])->save();

            if (array_key_exists('items', $data)) {
                $this->syncItems($locked, $customerId, $data['items']);
            }

            $this->recalculate($locked);

            return $this->load($locked);
        }, 5);
    }

    public function deleteDraft(SalesInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $locked = $this->lock($invoice);

            if ($locked->status !== SalesInvoice::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya sales invoice draft yang boleh dihapus.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $locked->items()->delete();
            $locked->delete();
        }, 5);
    }

    public function approve(SalesInvoice $invoice): SalesInvoice
    {
        return DB::transaction(function () use ($invoice): SalesInvoice {
            $locked = $this->lock($invoice);

            if ($locked->status === SalesInvoice::STATUS_APPROVED) {
                return $this->load($locked);
            }

            if ($locked->status !== SalesInvoice::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Sales invoice tidak dapat disetujui dari status ini.',
                    'DOCUMENT_STATE_INVALID',
                );
            }

            $day = $this->day($locked->doc_date);
            $this->periods->assertOpen($day, true);

            /** @var Collection<int, SalesInvoiceItem> $items */
            $items = $locked->items()
                ->with(['deliveryOrderItem.delivery', 'deliveryOrderItem.salesOrderItem', 'item', 'unit'])
                ->orderBy('delivery_order_item_id')
                ->orderBy('id')
                ->get();

            if ($items->isEmpty()) {
                throw ApiException::unprocessable(
                    'Sales invoice tidak memiliki baris item.',
                    'DOCUMENT_EMPTY',
                );
            }

            $doItemIds = $items->pluck('delivery_order_item_id')->map(static fn ($id): int => (int) $id)->all();

            /** @var Collection<int, DeliveryOrderItem> $doItems */
            $doItems = DeliveryOrderItem::query()
                ->with('delivery')
                ->whereIn('id', $doItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (DeliveryOrderItem $item): int => (int) $item->getKey());

            $soItemIds = $doItems
                ->pluck('sales_order_item_id')
                ->filter()
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            /** @var Collection<int, SalesOrderItem> $soItems */
            $soItems = SalesOrderItem::query()
                ->whereIn('id', $soItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (SalesOrderItem $item): int => (int) $item->getKey());

            foreach ($items as $item) {
                $doItem = $doItems->get((int) $item->delivery_order_item_id);

                if (! $doItem instanceof DeliveryOrderItem) {
                    throw ApiException::unprocessable(
                        'Baris delivery order tidak ditemukan.',
                        'SALES_INVOICE_LINE_INVALID',
                    );
                }

                if ($doItem->delivery === null || $doItem->delivery->status !== 'approved') {
                    throw ApiException::conflict(
                        'Sales invoice hanya dapat menagih delivery order approved.',
                        'DELIVERY_ORDER_NOT_APPROVED',
                    );
                }

                if ((int) $doItem->delivery->customer_id !== (int) $locked->customer_id) {
                    throw ApiException::unprocessable(
                        'Customer invoice tidak sesuai dengan customer delivery order.',
                        'SALES_INVOICE_CUSTOMER_MISMATCH',
                    );
                }

                $remaining = Decimal::sub(
                    (string) $doItem->qty_base,
                    (string) $doItem->invoiced_qty_base,
                    Decimal::QTY_SCALE,
                );

                if (Decimal::compare((string) $item->qty_base, $remaining, Decimal::QTY_SCALE) > 0) {
                    throw ApiException::unprocessable(
                        "Qty invoice melebihi sisa delivery order: sisa {$remaining}, diminta {$item->qty_base}.",
                        'SI_QTY_EXCEEDS_REMAINING',
                        ['items' => ["Sisa yang boleh ditagih {$remaining}."]],
                    );
                }

                $doItem->forceFill([
                    'invoiced_qty_base' => Decimal::add(
                        (string) $doItem->invoiced_qty_base,
                        (string) $item->qty_base,
                        Decimal::QTY_SCALE,
                    ),
                ])->save();

                $soItem = $doItem->sales_order_item_id === null
                    ? null
                    : $soItems->get((int) $doItem->sales_order_item_id);

                if ($soItem instanceof SalesOrderItem) {
                    $soItem->forceFill([
                        'invoiced_qty_base' => Decimal::add(
                            (string) $soItem->invoiced_qty_base,
                            (string) $item->qty_base,
                            Decimal::QTY_SCALE,
                        ),
                    ])->save();
                }
            }

            $journal = $this->postInvoiceJournal($locked, $items, $day);

            $locked->forceFill([
                'status' => SalesInvoice::STATUS_APPROVED,
                'mode' => SalesInvoice::MODE_FINAL,
                'journal_id' => $journal?->getKey(),
                'approved_by' => $this->actorId(),
                'approved_at' => now(),
            ])->save();

            $this->audit->record($locked, 'approved', ['status' => SalesInvoice::STATUS_DRAFT], [
                'status' => SalesInvoice::STATUS_APPROVED,
                'doc_no' => $locked->doc_no,
                'journal_id' => $locked->journal_id,
            ]);

            return $this->load($locked);
        }, 5);
    }

    public function disapprove(SalesInvoice $invoice): SalesInvoice
    {
        return DB::transaction(function () use ($invoice): SalesInvoice {
            $locked = $this->lock($invoice);

            if ($locked->status !== SalesInvoice::STATUS_APPROVED) {
                throw ApiException::conflict(
                    'Hanya sales invoice approved yang dapat dibatalkan.',
                    'DOCUMENT_NOT_APPROVED',
                );
            }

            $day = $this->day($locked->doc_date);
            $this->periods->assertOpen($day, true);

            if (Decimal::compare((string) $locked->paid_amount, '0', Decimal::MONEY_SCALE) > 0
                || $locked->allocations()->exists()) {
                throw ApiException::conflict(
                    'Sales invoice sudah memiliki pembayaran.',
                    'DOCUMENT_HAS_PAYMENTS',
                    ['references' => ['customer_payments']],
                );
            }

            /** @var Collection<int, SalesInvoiceItem> $items */
            $items = $locked->items()->orderBy('delivery_order_item_id')->orderBy('id')->get();

            $doItemIds = $items->pluck('delivery_order_item_id')->map(static fn ($id): int => (int) $id)->all();

            /** @var Collection<int, DeliveryOrderItem> $doItems */
            $doItems = DeliveryOrderItem::query()
                ->with('delivery')
                ->whereIn('id', $doItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (DeliveryOrderItem $item): int => (int) $item->getKey());

            $soItemIds = $doItems
                ->pluck('sales_order_item_id')
                ->filter()
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            /** @var Collection<int, SalesOrderItem> $soItems */
            $soItems = SalesOrderItem::query()
                ->whereIn('id', $soItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (SalesOrderItem $item): int => (int) $item->getKey());

            foreach ($items as $item) {
                $doItem = $doItems->get((int) $item->delivery_order_item_id);

                if ($doItem instanceof DeliveryOrderItem) {
                    $doItem->forceFill([
                        'invoiced_qty_base' => $this->subtractFloor(
                            (string) $doItem->invoiced_qty_base,
                            (string) $item->qty_base,
                        ),
                    ])->save();

                    $soItem = $doItem->sales_order_item_id === null
                        ? null
                        : $soItems->get((int) $doItem->sales_order_item_id);

                    if ($soItem instanceof SalesOrderItem) {
                        $soItem->forceFill([
                            'invoiced_qty_base' => $this->subtractFloor(
                                (string) $soItem->invoiced_qty_base,
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
                'status' => SalesInvoice::STATUS_DRAFT,
                'mode' => SalesInvoice::MODE_DRAFT,
                'journal_id' => null,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            $this->audit->record($locked, 'disapproved', ['status' => SalesInvoice::STATUS_APPROVED], [
                'status' => SalesInvoice::STATUS_DRAFT,
                'doc_no' => $locked->doc_no,
            ]);

            return $this->load($locked);
        }, 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(SalesInvoice $invoice, int $customerId, array $items): void
    {
        $invoice->items()->delete();

        $line = 1;

        foreach ($items as $row) {
            /** @var DeliveryOrderItem $doItem */
            $doItem = DeliveryOrderItem::query()
                ->with(['delivery', 'salesOrderItem', 'item', 'unit'])
                ->findOrFail((int) $row['delivery_order_item_id']);

            if ($doItem->delivery === null || $doItem->delivery->status !== 'approved') {
                throw ApiException::conflict(
                    'Sales invoice hanya dapat menagih delivery order approved.',
                    'DELIVERY_ORDER_NOT_APPROVED',
                );
            }

            if ((int) $doItem->delivery->customer_id !== $customerId) {
                throw ApiException::unprocessable(
                    'Customer invoice tidak sesuai dengan customer delivery order.',
                    'SALES_INVOICE_CUSTOMER_MISMATCH',
                );
            }

            $orderItem = $doItem->salesOrderItem;

            if (! $orderItem instanceof SalesOrderItem) {
                throw ApiException::unprocessable(
                    'Baris delivery order tidak terhubung ke sales order.',
                    'SALES_INVOICE_LINE_INVALID',
                );
            }

            /** @var Item $item */
            $item = $doItem->item;
            /** @var Unit $unit */
            $unit = $doItem->unit;

            $qty = Decimal::round((string) $row['qty'], Decimal::QTY_SCALE);
            $qtyBase = $this->units->toBase($item, $unit, $qty);
            $remaining = Decimal::sub(
                (string) $doItem->qty_base,
                (string) $doItem->invoiced_qty_base,
                Decimal::QTY_SCALE,
            );

            if (Decimal::compare($qtyBase, $remaining, Decimal::QTY_SCALE) > 0) {
                throw ApiException::unprocessable(
                    "Qty invoice melebihi sisa delivery order: sisa {$remaining}, diminta {$qtyBase}.",
                    'SI_QTY_EXCEEDS_REMAINING',
                    ['items' => ["Sisa yang boleh ditagih {$remaining}."]],
                );
            }

            $unitPrice = Decimal::round((string) $orderItem->unit_price, Decimal::QTY_SCALE);
            $unitPriceBase = Decimal::round((string) $orderItem->unit_price_base, Decimal::QTY_SCALE);
            $totalValue = Decimal::round(
                Decimal::mul($qtyBase, $unitPriceBase, Decimal::QTY_SCALE + 2),
                Decimal::MONEY_SCALE,
            );

            $invoice->items()->create([
                'delivery_order_item_id' => $doItem->getKey(),
                'item_id' => $item->getKey(),
                'unit_id' => $unit->getKey(),
                'line_no' => $line++,
                'qty' => $qty,
                'qty_base' => $qtyBase,
                'unit_price' => $unitPrice,
                'unit_price_base' => $unitPriceBase,
                'total_value' => $totalValue,
            ]);
        }
    }

    private function recalculate(SalesInvoice $invoice): void
    {
        $subtotal = '0.00';

        /** @var Collection<int, SalesInvoiceItem> $items */
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
     * @param  Collection<int, SalesInvoiceItem>  $items
     */
    private function postInvoiceJournal(
        SalesInvoice $invoice,
        Collection $items,
        CarbonImmutable $day,
    ): ?Journal {
        $total = Decimal::round((string) $invoice->total, Decimal::MONEY_SCALE);
        $vat = Decimal::round((string) $invoice->tax_amount, Decimal::MONEY_SCALE);

        if (Decimal::compare($total, '0', Decimal::MONEY_SCALE) <= 0) {
            return null;
        }

        /** @var array<int, string> $revenue */
        $revenue = [];

        foreach ($items as $item) {
            $itemModel = $item->item;

            if (! $itemModel instanceof Item) {
                continue;
            }

            $accountId = (int) $this->accounts->salesFor($itemModel)->getKey();
            $revenue[$accountId] = Decimal::add(
                $revenue[$accountId] ?? '0.00',
                (string) $item->total_value,
                Decimal::MONEY_SCALE,
            );
        }

        ksort($revenue);

        $lines = [
            [
                'account_id' => (int) $this->accounts->accountFor(AccountMapper::ACCOUNTS_RECEIVABLE)->getKey(),
                'debit' => $total,
            ],
        ];

        foreach ($revenue as $accountId => $amount) {
            if (Decimal::compare($amount, '0', Decimal::MONEY_SCALE) > 0) {
                $lines[] = ['account_id' => $accountId, 'credit' => Decimal::round($amount, Decimal::MONEY_SCALE)];
            }
        }

        if (Decimal::compare($vat, '0', Decimal::MONEY_SCALE) > 0) {
            $lines[] = [
                'account_id' => (int) $this->accounts->accountFor(AccountMapper::VAT_OUT)->getKey(),
                'credit' => $vat,
            ];
        }

        if (count($lines) < 2) {
            return null;
        }

        return $this->journals->post(
            $lines,
            ['type' => 'sales_invoice', 'id' => (int) $invoice->getKey()],
            $day,
            "Tagihan penjualan {$invoice->doc_no}",
        );
    }

    private function subtractFloor(string $current, string $amount): string
    {
        $result = Decimal::sub($current, $amount, Decimal::QTY_SCALE);

        return Decimal::compare($result, '0', Decimal::QTY_SCALE) < 0 ? '0.0000' : $result;
    }

    private function mode(mixed $value): string
    {
        $mode = $value === null || $value === '' ? SalesInvoice::MODE_DRAFT : strtolower((string) $value);

        if (! in_array($mode, [SalesInvoice::MODE_DRAFT, SalesInvoice::MODE_FINAL], true)) {
            throw ApiException::unprocessable(
                'Mode invoice harus draft atau final.',
                'SALES_INVOICE_MODE_INVALID',
                ['mode' => ['Gunakan draft atau final.']],
            );
        }

        return $mode;
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

    private function lock(SalesInvoice $invoice): SalesInvoice
    {
        /** @var SalesInvoice $locked */
        $locked = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

        return $locked;
    }

    private function load(SalesInvoice $invoice): SalesInvoice
    {
        return $invoice->refresh()->load([
            'customer',
            'journal',
            'items.item',
            'items.unit',
            'items.deliveryOrderItem',
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
