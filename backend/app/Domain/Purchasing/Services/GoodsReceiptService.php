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
use App\Domain\Inventory\Services\StockService;
use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Warehouse;
use App\Domain\Master\Services\UnitConversionService;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptItem;
use App\Domain\Purchasing\Models\PurchaseInvoice;
use App\Domain\Purchasing\Models\PurchaseInvoiceItem;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderItem;
use App\Exceptions\ApiException;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Goods Receipt dari Purchase Order.
 *
 * Approve menerima stok pada biaya PO bersih per base (tanpa pajak) dan
 * membentuk jurnal Dr Persediaan per kategori / Cr GR/IR. Parsial
 * diperbolehkan; qty tidak boleh melebihi sisa PO ditambah toleransi setting.
 */
final class GoodsReceiptService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly PeriodGuard $periods,
        private readonly UnitConversionService $units,
        private readonly StockService $stock,
        private readonly JournalService $journals,
        private readonly AccountMapper $accounts,
        private readonly PurchaseOrderService $orders,
        private readonly SettingService $settings,
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($data): GoodsReceipt {
            $day = $this->day($data['doc_date']);
            $period = $this->periods->assertOpen($day);
            /** @var PurchaseOrder $order */
            $order = PurchaseOrder::query()->findOrFail((int) $data['purchase_order_id']);
            $this->assertReceivable($order);
            Warehouse::query()->findOrFail((int) $order->warehouse_id);

            /** @var GoodsReceipt $receipt */
            $receipt = GoodsReceipt::query()->create([
                'doc_no' => $this->sequences->next('GR', $day),
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'purchase_order_id' => $order->getKey(),
                'supplier_id' => $order->supplier_id,
                'warehouse_id' => $order->warehouse_id,
                'status' => GoodsReceipt::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->actorId(),
            ]);

            $this->syncItems($receipt, $order, $data['items'] ?? []);

            return $this->load($receipt);
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(GoodsReceipt $receipt, array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $data): GoodsReceipt {
            $locked = $this->lock($receipt);

            if ($locked->status !== GoodsReceipt::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya goods receipt draft yang boleh diubah.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $day = array_key_exists('doc_date', $data)
                ? $this->day($data['doc_date'])
                : $this->day($locked->doc_date);
            $period = $this->periods->assertOpen($day);

            $orderId = (int) ($data['purchase_order_id'] ?? $locked->purchase_order_id);
            /** @var PurchaseOrder $order */
            $order = PurchaseOrder::query()->findOrFail($orderId);
            $this->assertReceivable($order);

            $locked->forceFill([
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'purchase_order_id' => $order->getKey(),
                'supplier_id' => $order->supplier_id,
                'warehouse_id' => $order->warehouse_id,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
            ])->save();

            if (array_key_exists('items', $data)) {
                $this->syncItems($locked, $order, $data['items']);
            }

            return $this->load($locked);
        }, 5);
    }

    public function deleteDraft(GoodsReceipt $receipt): void
    {
        DB::transaction(function () use ($receipt): void {
            $locked = $this->lock($receipt);

            if ($locked->status !== GoodsReceipt::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya goods receipt draft yang boleh dihapus.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $locked->items()->delete();
            $locked->delete();
        }, 5);
    }

    public function approve(GoodsReceipt $receipt): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt): GoodsReceipt {
            $locked = $this->lock($receipt);

            if ($locked->status === GoodsReceipt::STATUS_APPROVED) {
                return $this->load($locked);
            }

            if ($locked->status !== GoodsReceipt::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Goods receipt tidak dapat disetujui dari status ini.',
                    'DOCUMENT_STATE_INVALID',
                );
            }

            $day = $this->day($locked->doc_date);
            $this->periods->assertOpen($day, true);

            /** @var PurchaseOrder $order */
            $order = PurchaseOrder::query()->findOrFail((int) $locked->purchase_order_id);
            $this->assertReceivable($order);

            $lockedItems = $this->orders->lockOrderItems($order)
                ->keyBy(static fn (PurchaseOrderItem $item): int => (int) $item->getKey());

            /** @var Collection<int, GoodsReceiptItem> $items */
            $items = $locked->items()
                ->with(['item.category', 'unit'])
                ->orderBy('item_id')
                ->orderBy('id')
                ->get();

            if ($items->isEmpty()) {
                throw ApiException::unprocessable(
                    'Goods receipt tidak memiliki baris item.',
                    'DOCUMENT_EMPTY',
                );
            }

            $pairs = [];

            foreach ($items as $item) {
                $pairs[] = [
                    'item_id' => (int) $item->item_id,
                    'warehouse_id' => (int) $locked->warehouse_id,
                ];
            }

            $this->stock->lockBalances($pairs);

            $tolerance = $this->tolerance();

            foreach ($items as $item) {
                $poItem = $lockedItems->get((int) $item->purchase_order_item_id);

                if (! $poItem instanceof PurchaseOrderItem) {
                    throw ApiException::unprocessable(
                        'Baris goods receipt tidak terhubung ke purchase order.',
                        'GOODS_RECEIPT_LINE_INVALID',
                    );
                }

                $this->assertWithinRemaining($poItem, (string) $item->qty_base, $tolerance);
            }

            $inventory = [];

            foreach ($items as $item) {
                /** @var Item $itemModel */
                $itemModel = $item->item;
                $poItem = $lockedItems->get((int) $item->purchase_order_item_id);

                if (! $poItem instanceof PurchaseOrderItem) {
                    continue;
                }

                $this->stock->receive(
                    $itemModel,
                    (int) $locked->warehouse_id,
                    (string) $item->qty_base,
                    (string) $poItem->unit_price_base,
                    ['type' => 'GR', 'id' => (int) $locked->getKey(), 'line_id' => (int) $item->getKey()],
                    $day,
                );

                $accountId = (int) $this->accounts->inventoryFor($itemModel)->getKey();
                $inventory[$accountId] = Decimal::add(
                    $inventory[$accountId] ?? '0.00',
                    (string) $item->total_value,
                    Decimal::MONEY_SCALE,
                );

                $poItem->forceFill([
                    'received_qty_base' => Decimal::add(
                        (string) $poItem->received_qty_base,
                        (string) $item->qty_base,
                        Decimal::QTY_SCALE,
                    ),
                ])->save();
            }

            $journal = $this->postReceiptJournal($locked, $inventory, $day);

            $locked->forceFill([
                'status' => GoodsReceipt::STATUS_APPROVED,
                'journal_id' => $journal?->getKey(),
                'approved_by' => $this->actorId(),
                'approved_at' => now(),
            ])->save();

            $this->orders->refreshFulfillment($order);

            $this->audit->record($locked, 'approved', ['status' => GoodsReceipt::STATUS_DRAFT], [
                'status' => GoodsReceipt::STATUS_APPROVED,
                'doc_no' => $locked->doc_no,
                'journal_id' => $locked->journal_id,
            ]);

            return $this->load($locked);
        }, 5);
    }

    public function disapprove(GoodsReceipt $receipt): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt): GoodsReceipt {
            $locked = $this->lock($receipt);

            if ($locked->status !== GoodsReceipt::STATUS_APPROVED) {
                throw ApiException::conflict(
                    'Hanya goods receipt approved yang dapat dibatalkan.',
                    'DOCUMENT_NOT_APPROVED',
                );
            }

            $day = $this->day($locked->doc_date);
            $this->periods->assertOpen($day, true);

            if ($this->hasActiveInvoice($locked)) {
                throw ApiException::conflict(
                    'Goods receipt sudah ditagih oleh purchase invoice aktif.',
                    'DOCUMENT_HAS_CHILDREN',
                    ['references' => ['purchase_invoices']],
                );
            }

            /** @var PurchaseOrder $order */
            $order = PurchaseOrder::query()->findOrFail((int) $locked->purchase_order_id);
            $lockedItems = $this->orders->lockOrderItems($order)
                ->keyBy(static fn (PurchaseOrderItem $item): int => (int) $item->getKey());

            $this->stock->reverse(['type' => 'GR', 'id' => (int) $locked->getKey()], $day);

            $journal = $locked->journal_id !== null
                ? Journal::query()->find($locked->journal_id)
                : null;

            if ($journal instanceof Journal && $journal->status === Journal::STATUS_POSTED) {
                $this->journals->reverse($journal);
            }

            /** @var Collection<int, GoodsReceiptItem> $items */
            $items = $locked->items()->get();

            foreach ($items as $item) {
                $poItem = $lockedItems->get((int) $item->purchase_order_item_id);

                if (! $poItem instanceof PurchaseOrderItem) {
                    continue;
                }

                $received = Decimal::sub(
                    (string) $poItem->received_qty_base,
                    (string) $item->qty_base,
                    Decimal::QTY_SCALE,
                );

                $poItem->forceFill([
                    'received_qty_base' => Decimal::compare($received, '0', Decimal::QTY_SCALE) < 0
                        ? '0.0000'
                        : $received,
                ])->save();
            }

            $locked->forceFill([
                'status' => GoodsReceipt::STATUS_DRAFT,
                'journal_id' => null,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            $this->orders->refreshFulfillment($order);

            $this->audit->record($locked, 'disapproved', ['status' => GoodsReceipt::STATUS_APPROVED], [
                'status' => GoodsReceipt::STATUS_DRAFT,
                'doc_no' => $locked->doc_no,
            ]);

            return $this->load($locked);
        }, 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(GoodsReceipt $receipt, PurchaseOrder $order, array $items): void
    {
        $receipt->items()->delete();

        $line = 1;
        $tolerance = $this->tolerance();
        $poItems = $order->items()->get()->keyBy(static fn (PurchaseOrderItem $item): int => (int) $item->getKey());

        foreach ($items as $row) {
            $poItem = $poItems->get((int) $row['purchase_order_item_id']);

            if (! $poItem instanceof PurchaseOrderItem) {
                throw ApiException::unprocessable(
                    'Baris purchase order tidak ditemukan pada dokumen ini.',
                    'GOODS_RECEIPT_LINE_INVALID',
                    ['items' => ['Baris PO tidak sesuai.']],
                );
            }

            /** @var Item $item */
            $item = Item::query()->findOrFail((int) $poItem->item_id);
            /** @var \App\Domain\Master\Models\Unit $unit */
            $unit = \App\Domain\Master\Models\Unit::query()->findOrFail((int) $poItem->unit_id);

            $qty = Decimal::round((string) $row['qty'], Decimal::QTY_SCALE);
            $qtyBase = $this->units->toBase($item, $unit, $qty);
            $this->assertWithinRemaining($poItem, $qtyBase, $tolerance);

            $total = Decimal::round(
                Decimal::mul($qtyBase, (string) $poItem->unit_price_base, Decimal::QTY_SCALE + 2),
                Decimal::MONEY_SCALE,
            );

            $receipt->items()->create([
                'purchase_order_item_id' => $poItem->getKey(),
                'item_id' => $item->getKey(),
                'unit_id' => $unit->getKey(),
                'line_no' => $line++,
                'qty' => $qty,
                'qty_base' => $qtyBase,
                'unit_cost_base' => (string) $poItem->unit_price_base,
                'total_value' => $total,
                'invoiced_qty_base' => '0.0000',
            ]);
        }
    }

    private function assertReceivable(PurchaseOrder $order): void
    {
        if ($order->status !== PurchaseOrder::STATUS_APPROVED) {
            throw ApiException::conflict(
                'Goods receipt hanya dapat dibuat dari purchase order approved.',
                'PURCHASE_ORDER_NOT_APPROVED',
            );
        }
    }

    private function assertWithinRemaining(PurchaseOrderItem $poItem, string $qtyBase, string $tolerance): void
    {
        $remaining = Decimal::sub((string) $poItem->qty_base, (string) $poItem->received_qty_base, Decimal::QTY_SCALE);
        $allowed = Decimal::round(
            Decimal::div(
                Decimal::mul($remaining, Decimal::add('100', $tolerance, 4), 8),
                '100',
                8,
            ),
            Decimal::QTY_SCALE,
        );

        if (Decimal::compare($qtyBase, $allowed, Decimal::QTY_SCALE) > 0) {
            $item = $poItem->item;

            throw ApiException::unprocessable(
                'Qty goods receipt melebihi sisa purchase order'
                .($item instanceof Item ? " untuk {$item->name}" : '')
                .": sisa {$remaining}, diminta {$qtyBase}, toleransi {$tolerance}%.",
                'GR_QTY_EXCEEDS_REMAINING',
                ['items' => ["Sisa yang boleh diterima {$allowed}."]],
            );
        }
    }

    private function tolerance(): string
    {
        $tolerance = Decimal::round($this->settings->decimal('gr_over_receipt_tolerance_pct', '0'), 2);

        return Decimal::isNegative($tolerance) ? '0.00' : $tolerance;
    }

    /**
     * @param  array<int, string>  $inventory
     */
    private function postReceiptJournal(GoodsReceipt $receipt, array $inventory, CarbonImmutable $day): ?Journal
    {
        $total = '0.00';

        foreach ($inventory as $amount) {
            $total = Decimal::add($total, $amount, Decimal::MONEY_SCALE);
        }

        if (Decimal::compare($total, '0', Decimal::MONEY_SCALE) <= 0) {
            return null;
        }

        $lines = [];

        ksort($inventory);

        foreach ($inventory as $accountId => $amount) {
            if (Decimal::compare($amount, '0', Decimal::MONEY_SCALE) > 0) {
                $lines[] = ['account_id' => $accountId, 'debit' => Decimal::round($amount, Decimal::MONEY_SCALE)];
            }
        }

        $lines[] = [
            'account_id' => (int) $this->accounts->accountFor(AccountMapper::GRIR)->getKey(),
            'credit' => $total,
        ];

        return $this->journals->post(
            $lines,
            ['type' => 'goods_receipt', 'id' => (int) $receipt->getKey()],
            $day,
            "Penerimaan barang {$receipt->doc_no}",
        );
    }

    private function hasActiveInvoice(GoodsReceipt $receipt): bool
    {
        $grItemIds = $receipt->items()->pluck('id')->all();

        if ($grItemIds === []) {
            return false;
        }

        return PurchaseInvoiceItem::query()
            ->whereIn('goods_receipt_item_id', $grItemIds)
            ->whereHas('invoice', static function ($query): void {
                $query->whereIn('status', [PurchaseInvoice::STATUS_DRAFT, PurchaseInvoice::STATUS_APPROVED])
                    ->whereNull('deleted_at');
            })
            ->exists();
    }

    private function lock(GoodsReceipt $receipt): GoodsReceipt
    {
        /** @var GoodsReceipt $locked */
        $locked = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->getKey());

        return $locked;
    }

    private function load(GoodsReceipt $receipt): GoodsReceipt
    {
        return $receipt->refresh()->load([
            'purchaseOrder',
            'supplier',
            'warehouse',
            'journal',
            'items.item',
            'items.unit',
            'items.purchaseOrderItem',
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
