<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Accounting\Models\Journal;
use App\Domain\Accounting\Services\AccountMapper;
use App\Domain\Accounting\Services\JournalService;
use App\Domain\Accounting\Services\PeriodGuard;
use App\Domain\Core\Services\AuditTrailService;
use App\Domain\Core\Services\DocumentSequenceService;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Unit;
use App\Domain\Master\Services\UnitConversionService;
use App\Domain\Sales\Models\DeliveryOrder;
use App\Domain\Sales\Models\DeliveryOrderItem;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Models\SalesOrderItem;
use App\Exceptions\ApiException;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Delivery Order (Surat Jalan) dari Sales Order.
 *
 * Approve mengeluarkan stok lewat StockService.issueWithValue() pada average
 * cost saat keluar, menyimpan cogs_unit_cost_base per baris, dan membentuk
 * jurnal Dr HPP per kategori / Cr Persediaan per kategori. Qty dibatasi sisa
 * SO dan ketersediaan stok.
 */
final class DeliveryOrderService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly PeriodGuard $periods,
        private readonly UnitConversionService $units,
        private readonly StockService $stock,
        private readonly JournalService $journals,
        private readonly AccountMapper $accounts,
        private readonly SalesOrderService $orders,
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): DeliveryOrder
    {
        return DB::transaction(function () use ($data): DeliveryOrder {
            $day = $this->day($data['doc_date']);
            $period = $this->periods->assertOpen($day);
            /** @var SalesOrder $order */
            $order = SalesOrder::query()->findOrFail((int) $data['sales_order_id']);
            $this->assertDeliverable($order);

            /** @var DeliveryOrder $delivery */
            $delivery = DeliveryOrder::query()->create([
                'doc_no' => $this->sequences->next('DO', $day),
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'sales_order_id' => $order->getKey(),
                'customer_id' => $order->customer_id,
                'warehouse_id' => $order->warehouse_id,
                'status' => DeliveryOrder::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->actorId(),
            ]);

            $this->syncItems($delivery, $order, $data['items'] ?? []);

            return $this->load($delivery);
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(DeliveryOrder $delivery, array $data): DeliveryOrder
    {
        return DB::transaction(function () use ($delivery, $data): DeliveryOrder {
            $locked = $this->lock($delivery);

            if ($locked->status !== DeliveryOrder::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya delivery order draft yang boleh diubah.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $day = array_key_exists('doc_date', $data)
                ? $this->day($data['doc_date'])
                : $this->day($locked->doc_date);
            $period = $this->periods->assertOpen($day);

            $orderId = (int) ($data['sales_order_id'] ?? $locked->sales_order_id);
            /** @var SalesOrder $order */
            $order = SalesOrder::query()->findOrFail($orderId);
            $this->assertDeliverable($order);

            $locked->forceFill([
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'sales_order_id' => $order->getKey(),
                'customer_id' => $order->customer_id,
                'warehouse_id' => $order->warehouse_id,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
            ])->save();

            if (array_key_exists('items', $data)) {
                $this->syncItems($locked, $order, $data['items']);
            }

            return $this->load($locked);
        }, 5);
    }

    public function deleteDraft(DeliveryOrder $delivery): void
    {
        DB::transaction(function () use ($delivery): void {
            $locked = $this->lock($delivery);

            if ($locked->status !== DeliveryOrder::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya delivery order draft yang boleh dihapus.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $locked->items()->delete();
            $locked->delete();
        }, 5);
    }

    public function approve(DeliveryOrder $delivery): DeliveryOrder
    {
        return DB::transaction(function () use ($delivery): DeliveryOrder {
            $locked = $this->lock($delivery);

            if ($locked->status === DeliveryOrder::STATUS_APPROVED) {
                return $this->load($locked);
            }

            if ($locked->status !== DeliveryOrder::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Delivery order tidak dapat disetujui dari status ini.',
                    'DOCUMENT_STATE_INVALID',
                );
            }

            $day = $this->day($locked->doc_date);
            $this->periods->assertOpen($day, true);

            /** @var SalesOrder $order */
            $order = SalesOrder::query()->findOrFail((int) $locked->sales_order_id);
            $this->assertDeliverable($order);

            $orderItems = $this->orders->lockOrderItems($order)
                ->keyBy(static fn (SalesOrderItem $item): int => (int) $item->getKey());

            /** @var Collection<int, DeliveryOrderItem> $items */
            $items = $locked->items()
                ->with(['item.category', 'unit'])
                ->orderBy('item_id')
                ->orderBy('id')
                ->get();

            if ($items->isEmpty()) {
                throw ApiException::unprocessable(
                    'Delivery order tidak memiliki baris item.',
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

            foreach ($items as $item) {
                $orderItem = $orderItems->get((int) $item->sales_order_item_id);

                if (! $orderItem instanceof SalesOrderItem) {
                    throw ApiException::unprocessable(
                        'Baris delivery order tidak terhubung ke sales order.',
                        'DELIVERY_ORDER_LINE_INVALID',
                    );
                }

                $remaining = Decimal::sub(
                    (string) $orderItem->qty_base,
                    (string) $orderItem->delivered_qty_base,
                    Decimal::QTY_SCALE,
                );

                if (Decimal::compare((string) $item->qty_base, $remaining, Decimal::QTY_SCALE) > 0) {
                    throw ApiException::unprocessable(
                        "Qty delivery order melebihi sisa sales order: sisa {$remaining}, "
                        ."diminta {$item->qty_base}.",
                        'DO_QTY_EXCEEDS_REMAINING',
                        ['items' => ["Sisa yang boleh dikirim {$remaining}."]],
                    );
                }
            }

            $cogs = [];
            $inventory = [];

            foreach ($items as $item) {
                /** @var Item $itemModel */
                $itemModel = $item->item;
                $orderItem = $orderItems->get((int) $item->sales_order_item_id);

                if (! $orderItem instanceof SalesOrderItem) {
                    continue;
                }

                $issued = $this->stock->issueWithValue(
                    $itemModel,
                    (int) $locked->warehouse_id,
                    (string) $item->qty_base,
                    ['type' => 'DO', 'id' => (int) $locked->getKey(), 'line_id' => (int) $item->getKey()],
                    $day,
                );

                $item->forceFill([
                    'cogs_unit_cost_base' => $issued['unit_cost'],
                    'total_cogs' => $issued['total_value'],
                ])->save();

                $cogsAccountId = (int) $this->accounts->cogsFor($itemModel)->getKey();
                $inventoryAccountId = (int) $this->accounts->inventoryFor($itemModel)->getKey();

                $cogs[$cogsAccountId] = Decimal::add(
                    $cogs[$cogsAccountId] ?? '0.00',
                    $issued['total_value'],
                    Decimal::MONEY_SCALE,
                );
                $inventory[$inventoryAccountId] = Decimal::add(
                    $inventory[$inventoryAccountId] ?? '0.00',
                    $issued['total_value'],
                    Decimal::MONEY_SCALE,
                );

                $orderItem->forceFill([
                    'delivered_qty_base' => Decimal::add(
                        (string) $orderItem->delivered_qty_base,
                        (string) $item->qty_base,
                        Decimal::QTY_SCALE,
                    ),
                ])->save();
            }

            $journal = $this->postDeliveryJournal($locked, $cogs, $inventory, $day);

            $locked->forceFill([
                'status' => DeliveryOrder::STATUS_APPROVED,
                'journal_id' => $journal?->getKey(),
                'approved_by' => $this->actorId(),
                'approved_at' => now(),
            ])->save();

            $this->orders->refreshFulfillment($order);

            $this->audit->record($locked, 'approved', ['status' => DeliveryOrder::STATUS_DRAFT], [
                'status' => DeliveryOrder::STATUS_APPROVED,
                'doc_no' => $locked->doc_no,
                'journal_id' => $locked->journal_id,
            ]);

            return $this->load($locked);
        }, 5);
    }

    public function disapprove(DeliveryOrder $delivery): DeliveryOrder
    {
        return DB::transaction(function () use ($delivery): DeliveryOrder {
            $locked = $this->lock($delivery);

            if ($locked->status !== DeliveryOrder::STATUS_APPROVED) {
                throw ApiException::conflict(
                    'Hanya delivery order approved yang dapat dibatalkan.',
                    'DOCUMENT_NOT_APPROVED',
                );
            }

            $day = $this->day($locked->doc_date);
            $this->periods->assertOpen($day, true);

            if ($this->hasActiveInvoice($locked)) {
                throw ApiException::conflict(
                    'Delivery order sudah ditagih oleh sales invoice aktif.',
                    'DOCUMENT_HAS_CHILDREN',
                    ['references' => ['sales_invoices']],
                );
            }

            /** @var SalesOrder $order */
            $order = SalesOrder::query()->findOrFail((int) $locked->sales_order_id);
            $orderItems = $this->orders->lockOrderItems($order)
                ->keyBy(static fn (SalesOrderItem $item): int => (int) $item->getKey());

            $this->stock->reverse(['type' => 'DO', 'id' => (int) $locked->getKey()], $day);

            $journal = $locked->journal_id !== null
                ? Journal::query()->find($locked->journal_id)
                : null;

            if ($journal instanceof Journal && $journal->status === Journal::STATUS_POSTED) {
                $this->journals->reverse($journal);
            }

            /** @var Collection<int, DeliveryOrderItem> $items */
            $items = $locked->items()->get();

            foreach ($items as $item) {
                $orderItem = $orderItems->get((int) $item->sales_order_item_id);

                if (! $orderItem instanceof SalesOrderItem) {
                    continue;
                }

                $delivered = Decimal::sub(
                    (string) $orderItem->delivered_qty_base,
                    (string) $item->qty_base,
                    Decimal::QTY_SCALE,
                );

                $orderItem->forceFill([
                    'delivered_qty_base' => Decimal::compare($delivered, '0', Decimal::QTY_SCALE) < 0
                        ? '0.0000'
                        : $delivered,
                ])->save();
            }

            $locked->forceFill([
                'status' => DeliveryOrder::STATUS_DRAFT,
                'journal_id' => null,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            $this->orders->refreshFulfillment($order);

            $this->audit->record($locked, 'disapproved', ['status' => DeliveryOrder::STATUS_APPROVED], [
                'status' => DeliveryOrder::STATUS_DRAFT,
                'doc_no' => $locked->doc_no,
            ]);

            return $this->load($locked);
        }, 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(DeliveryOrder $delivery, SalesOrder $order, array $items): void
    {
        $delivery->items()->delete();

        $line = 1;
        $orderItems = $order->items()->get()->keyBy(
            static fn (SalesOrderItem $item): int => (int) $item->getKey(),
        );

        foreach ($items as $row) {
            $orderItem = $orderItems->get((int) $row['sales_order_item_id']);

            if (! $orderItem instanceof SalesOrderItem) {
                throw ApiException::unprocessable(
                    'Baris sales order tidak ditemukan pada dokumen ini.',
                    'DELIVERY_ORDER_LINE_INVALID',
                    ['items' => ['Baris SO tidak sesuai.']],
                );
            }

            /** @var Item $item */
            $item = Item::query()->findOrFail((int) $orderItem->item_id);
            /** @var Unit $unit */
            $unit = Unit::query()->findOrFail((int) $orderItem->unit_id);

            $qty = Decimal::round((string) $row['qty'], Decimal::QTY_SCALE);
            $qtyBase = $this->units->toBase($item, $unit, $qty);
            $remaining = Decimal::sub(
                (string) $orderItem->qty_base,
                (string) $orderItem->delivered_qty_base,
                Decimal::QTY_SCALE,
            );

            if (Decimal::compare($qtyBase, $remaining, Decimal::QTY_SCALE) > 0) {
                throw ApiException::unprocessable(
                    "Qty delivery order melebihi sisa sales order: sisa {$remaining}, diminta {$qtyBase}.",
                    'DO_QTY_EXCEEDS_REMAINING',
                    ['items' => ["Sisa yang boleh dikirim {$remaining}."]],
                );
            }

            $delivery->items()->create([
                'sales_order_item_id' => $orderItem->getKey(),
                'item_id' => $item->getKey(),
                'unit_id' => $unit->getKey(),
                'line_no' => $line++,
                'qty' => $qty,
                'qty_base' => $qtyBase,
                'cogs_unit_cost_base' => '0.0000',
                'total_cogs' => '0.00',
                'invoiced_qty_base' => '0.0000',
            ]);
        }
    }

    private function assertDeliverable(SalesOrder $order): void
    {
        if ($order->status !== SalesOrder::STATUS_APPROVED) {
            throw ApiException::conflict(
                'Delivery order hanya dapat dibuat dari sales order approved.',
                'SALES_ORDER_NOT_APPROVED',
            );
        }
    }

    /**
     * @param  array<int, string>  $cogs
     * @param  array<int, string>  $inventory
     */
    private function postDeliveryJournal(
        DeliveryOrder $delivery,
        array $cogs,
        array $inventory,
        CarbonImmutable $day,
    ): ?Journal {
        $total = '0.00';

        foreach ($cogs as $amount) {
            $total = Decimal::add($total, $amount, Decimal::MONEY_SCALE);
        }

        if (Decimal::compare($total, '0', Decimal::MONEY_SCALE) <= 0) {
            return null;
        }

        $lines = [];

        ksort($cogs);

        foreach ($cogs as $accountId => $amount) {
            if (Decimal::compare($amount, '0', Decimal::MONEY_SCALE) > 0) {
                $lines[] = ['account_id' => $accountId, 'debit' => Decimal::round($amount, Decimal::MONEY_SCALE)];
            }
        }

        ksort($inventory);

        foreach ($inventory as $accountId => $amount) {
            if (Decimal::compare($amount, '0', Decimal::MONEY_SCALE) > 0) {
                $lines[] = ['account_id' => $accountId, 'credit' => Decimal::round($amount, Decimal::MONEY_SCALE)];
            }
        }

        if (count($lines) < 2) {
            return null;
        }

        return $this->journals->post(
            $lines,
            ['type' => 'delivery_order', 'id' => (int) $delivery->getKey()],
            $day,
            "Pengiriman barang {$delivery->doc_no}",
        );
    }

    /**
     * DO dianggap sudah ditagih hanya bila ada qty yang masih tercatat pada
     * invoice approved. Invoice yang di-disapprove mengembalikan
     * invoiced_qty_base ke nol sehingga DO dapat dibatalkan kembali.
     */
    private function hasActiveInvoice(DeliveryOrder $delivery): bool
    {
        return $delivery->items()
            ->where('invoiced_qty_base', '>', 0)
            ->exists();
    }

    private function lock(DeliveryOrder $delivery): DeliveryOrder
    {
        /** @var DeliveryOrder $locked */
        $locked = DeliveryOrder::query()->lockForUpdate()->findOrFail($delivery->getKey());

        return $locked;
    }

    private function load(DeliveryOrder $delivery): DeliveryOrder
    {
        return $delivery->refresh()->load([
            'salesOrder',
            'customer',
            'warehouse',
            'journal',
            'items.item',
            'items.unit',
            'items.salesOrderItem',
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
