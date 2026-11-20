<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Domain\Accounting\Services\PeriodGuard;
use App\Domain\Core\Services\AuditTrailService;
use App\Domain\Core\Services\DocumentSequenceService;
use App\Domain\Core\Services\SettingService;
use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Supplier;
use App\Domain\Master\Models\Unit;
use App\Domain\Master\Models\Warehouse;
use App\Domain\Master\Services\UnitConversionService;
use App\Domain\Purchasing\Models\GoodsReceipt;
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
 * Purchase Order.
 *
 * - Diskon baris dan PPN dihitung dengan pembulatan 2 desimal HALF_UP.
 * - unit_price_base adalah harga bersih per satuan base (tanpa pajak) dan
 *   menjadi biaya penerimaan GR.
 * - PO otomatis closed saat seluruh qty base diterima; penutupan manual
 *   ditandai closed_manually agar disapprove GR tidak membuka kembali PO yang
 *   memang ditutup pengguna.
 */
final class PurchaseOrderService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly PeriodGuard $periods,
        private readonly UnitConversionService $units,
        private readonly SettingService $settings,
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data): PurchaseOrder {
            $day = $this->day($data['doc_date']);
            $period = $this->periods->assertOpen($day);
            $supplierId = (int) $data['supplier_id'];
            $warehouseId = (int) $data['warehouse_id'];
            Supplier::query()->findOrFail($supplierId);
            Warehouse::query()->findOrFail($warehouseId);

            $expected = isset($data['expected_date'])
                ? $this->day($data['expected_date'])
                : null;

            /** @var PurchaseOrder $order */
            $order = PurchaseOrder::query()->create([
                'doc_no' => $this->sequences->next('PO', $day),
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'purchase_request_id' => isset($data['purchase_request_id'])
                    ? (int) $data['purchase_request_id']
                    : null,
                'supplier_id' => $supplierId,
                'warehouse_id' => $warehouseId,
                'expected_date' => $expected?->toDateString(),
                'status' => PurchaseOrder::STATUS_DRAFT,
                'tax_rate' => $this->taxRate($data['tax_rate'] ?? null),
                'closed_manually' => false,
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->actorId(),
            ]);

            $this->syncItems($order, $data['items'] ?? []);
            $this->recalculate($order);

            return $this->load($order);
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PurchaseOrder $order, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data): PurchaseOrder {
            $locked = $this->lock($order);

            if ($locked->status !== PurchaseOrder::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya purchase order draft yang boleh diubah.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $day = array_key_exists('doc_date', $data)
                ? $this->day($data['doc_date'])
                : $this->day($locked->doc_date);
            $period = $this->periods->assertOpen($day);
            $supplierId = (int) ($data['supplier_id'] ?? $locked->supplier_id);
            $warehouseId = (int) ($data['warehouse_id'] ?? $locked->warehouse_id);
            Supplier::query()->findOrFail($supplierId);
            Warehouse::query()->findOrFail($warehouseId);

            $expected = array_key_exists('expected_date', $data)
                ? ($data['expected_date'] === null ? null : $this->day($data['expected_date']))
                : $locked->expected_date;

            $locked->forceFill([
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'supplier_id' => $supplierId,
                'warehouse_id' => $warehouseId,
                'expected_date' => $expected?->toDateString(),
                'tax_rate' => array_key_exists('tax_rate', $data)
                    ? $this->taxRate($data['tax_rate'])
                    : (string) $locked->tax_rate,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
            ])->save();

            if (array_key_exists('items', $data)) {
                $this->syncItems($locked, $data['items']);
            }

            $this->recalculate($locked);

            return $this->load($locked);
        }, 5);
    }

    public function deleteDraft(PurchaseOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $locked = $this->lock($order);

            if ($locked->status !== PurchaseOrder::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya purchase order draft yang boleh dihapus.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $locked->items()->delete();
            $locked->delete();
        }, 5);
    }

    public function approve(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order): PurchaseOrder {
            $locked = $this->lock($order);

            if ($locked->status === PurchaseOrder::STATUS_APPROVED) {
                return $this->load($locked);
            }

            if ($locked->status !== PurchaseOrder::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Purchase order tidak dapat disetujui dari status ini.',
                    'DOCUMENT_STATE_INVALID',
                );
            }

            $this->periods->assertOpen($this->day($locked->doc_date), true);

            if ($locked->items()->count() === 0) {
                throw ApiException::unprocessable(
                    'Purchase order tidak memiliki baris item.',
                    'DOCUMENT_EMPTY',
                );
            }

            $this->recalculate($locked);

            $locked->forceFill([
                'status' => PurchaseOrder::STATUS_APPROVED,
                'approved_by' => $this->actorId(),
                'approved_at' => now(),
            ])->save();

            $this->audit->record($locked, 'approved', ['status' => PurchaseOrder::STATUS_DRAFT], [
                'status' => PurchaseOrder::STATUS_APPROVED,
                'doc_no' => $locked->doc_no,
                'total' => $locked->total,
            ]);

            return $this->load($locked);
        }, 5);
    }

    public function close(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order): PurchaseOrder {
            $locked = $this->lock($order);

            if ($locked->status !== PurchaseOrder::STATUS_APPROVED) {
                throw ApiException::conflict(
                    'Hanya purchase order approved yang dapat ditutup.',
                    'DOCUMENT_NOT_APPROVED',
                );
            }

            $locked->forceFill([
                'status' => PurchaseOrder::STATUS_CLOSED,
                'closed_manually' => true,
            ])->save();

            $this->audit->record($locked, 'closed', ['status' => PurchaseOrder::STATUS_APPROVED], [
                'status' => PurchaseOrder::STATUS_CLOSED,
                'doc_no' => $locked->doc_no,
            ]);

            return $this->load($locked);
        }, 5);
    }

    public function disapprove(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order): PurchaseOrder {
            $locked = $this->lock($order);

            if ($locked->status !== PurchaseOrder::STATUS_APPROVED) {
                throw ApiException::conflict(
                    'Hanya purchase order approved yang dapat dibatalkan.',
                    'DOCUMENT_NOT_APPROVED',
                );
            }

            $this->periods->assertOpen($this->day($locked->doc_date), true);

            $hasActiveReceipt = GoodsReceipt::query()
                ->where('purchase_order_id', $locked->getKey())
                ->whereIn('status', [GoodsReceipt::STATUS_DRAFT, GoodsReceipt::STATUS_APPROVED])
                ->whereNull('deleted_at')
                ->exists();

            if ($hasActiveReceipt) {
                throw ApiException::conflict(
                    'Purchase order masih memiliki goods receipt aktif.',
                    'DOCUMENT_HAS_CHILDREN',
                    ['references' => ['goods_receipts']],
                );
            }

            $locked->forceFill([
                'status' => PurchaseOrder::STATUS_DRAFT,
                'approved_by' => null,
                'approved_at' => null,
                'closed_manually' => false,
            ])->save();

            $this->audit->record($locked, 'disapproved', ['status' => PurchaseOrder::STATUS_APPROVED], [
                'status' => PurchaseOrder::STATUS_DRAFT,
                'doc_no' => $locked->doc_no,
            ]);

            return $this->load($locked);
        }, 5);
    }

    /**
     * Menyesuaikan status PO setelah penerimaan berubah.
     */
    public function refreshFulfillment(PurchaseOrder $order): PurchaseOrder
    {
        /** @var PurchaseOrder $fresh */
        $fresh = PurchaseOrder::query()->findOrFail($order->getKey());

        /** @var Collection<int, PurchaseOrderItem> $items */
        $items = $fresh->items()->get();

        if ($items->isEmpty()) {
            return $fresh;
        }

        $fullyReceived = $items->every(
            static fn (PurchaseOrderItem $item): bool => Decimal::compare(
                (string) $item->received_qty_base,
                (string) $item->qty_base,
                Decimal::QTY_SCALE,
            ) >= 0,
        );

        if ($fullyReceived && $fresh->status === PurchaseOrder::STATUS_APPROVED) {
            $fresh->forceFill([
                'status' => PurchaseOrder::STATUS_CLOSED,
                'closed_manually' => false,
            ])->save();
        } elseif (! $fullyReceived
            && $fresh->status === PurchaseOrder::STATUS_CLOSED
            && ! $fresh->closed_manually) {
            $fresh->forceFill([
                'status' => PurchaseOrder::STATUS_APPROVED,
                'closed_manually' => false,
            ])->save();
        }

        return $fresh;
    }

    /**
     * Mengunci header dan seluruh baris PO dengan urutan id agar aman dari
     * deadlock. Dipakai GR saat menerima stok.
     *
     * @return Collection<int, PurchaseOrderItem>
     */
    public function lockOrderItems(PurchaseOrder $order): Collection
    {
        PurchaseOrder::query()->lockForUpdate()->findOrFail($order->getKey());

        /** @var Collection<int, PurchaseOrderItem> $items */
        $items = PurchaseOrderItem::query()
            ->with('item')
            ->where('purchase_order_id', $order->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(PurchaseOrder $order, array $items): void
    {
        $order->items()->delete();

        $line = 1;

        foreach ($items as $row) {
            /** @var Item $item */
            $item = Item::query()->findOrFail((int) $row['item_id']);
            /** @var Unit $unit */
            $unit = Unit::query()->findOrFail((int) $row['unit_id']);

            $qty = Decimal::round((string) $row['qty'], Decimal::QTY_SCALE);
            $qtyBase = $this->units->toBase($item, $unit, $qty);
            $unitPrice = Decimal::round((string) $row['unit_price'], Decimal::QTY_SCALE);
            $discount = Decimal::round((string) ($row['discount_pct'] ?? '0'), 2);

            if (Decimal::isNegative($unitPrice)) {
                throw ApiException::unprocessable(
                    'Harga satuan tidak boleh negatif.',
                    'PURCHASE_PRICE_INVALID',
                    ['unit_price' => ['Harga satuan tidak boleh negatif.']],
                );
            }

            if (Decimal::isNegative($discount) || Decimal::compare($discount, '100', 2) > 0) {
                throw ApiException::unprocessable(
                    'Diskon baris harus antara 0 dan 100 persen.',
                    'PURCHASE_DISCOUNT_INVALID',
                    ['discount_pct' => ['Diskon harus antara 0 dan 100.']],
                );
            }

            $grossUnitBase = $this->units->unitCostPerBase($item, $unit, $unitPrice);
            $grossLine = Decimal::round(
                Decimal::mul($qtyBase, $grossUnitBase, Decimal::QTY_SCALE + 2),
                Decimal::MONEY_SCALE,
            );
            $netUnitPrice = Decimal::div(
                Decimal::mul($unitPrice, Decimal::sub('100', $discount, 4), 6),
                '100',
                6,
            );
            $netUnitBase = $this->units->unitCostPerBase($item, $unit, $netUnitPrice);
            $lineTotal = Decimal::round(
                Decimal::mul($qtyBase, $netUnitBase, Decimal::QTY_SCALE + 2),
                Decimal::MONEY_SCALE,
            );
            $lineDiscount = Decimal::sub($grossLine, $lineTotal, Decimal::MONEY_SCALE);

            $order->items()->create([
                'item_id' => $item->getKey(),
                'unit_id' => $unit->getKey(),
                'line_no' => $line++,
                'qty' => $qty,
                'qty_base' => $qtyBase,
                'unit_price' => $unitPrice,
                'discount_pct' => $discount,
                'unit_price_base' => $netUnitBase,
                'line_total' => $lineTotal,
                'received_qty_base' => '0.0000',
                'invoiced_qty_base' => '0.0000',
            ]);
        }
    }

    private function recalculate(PurchaseOrder $order): void
    {
        $subtotal = '0.00';
        $discount = '0.00';

        /** @var Collection<int, PurchaseOrderItem> $items */
        $items = $order->items()->with(['item', 'unit'])->get();

        foreach ($items as $item) {
            if ($item->item === null || $item->unit === null) {
                continue;
            }

            $grossUnitBase = $this->units->unitCostPerBase(
                $item->item,
                $item->unit,
                (string) $item->unit_price,
            );
            $grossLine = Decimal::round(
                Decimal::mul((string) $item->qty_base, $grossUnitBase, Decimal::QTY_SCALE + 2),
                Decimal::MONEY_SCALE,
            );

            $subtotal = Decimal::add($subtotal, $grossLine, Decimal::MONEY_SCALE);
            $discount = Decimal::add(
                $discount,
                Decimal::sub($grossLine, (string) $item->line_total, Decimal::MONEY_SCALE),
                Decimal::MONEY_SCALE,
            );
        }

        $taxRate = Decimal::round((string) $order->tax_rate, 2);
        $taxBase = Decimal::sub($subtotal, $discount, Decimal::MONEY_SCALE);
        $taxAmount = Decimal::round(
            Decimal::div(Decimal::mul($taxBase, $taxRate, 6), '100', 6),
            Decimal::MONEY_SCALE,
        );
        $total = Decimal::add($taxBase, $taxAmount, Decimal::MONEY_SCALE);

        $order->forceFill([
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'tax_amount' => $taxAmount,
            'total' => $total,
        ])->save();
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

    private function lock(PurchaseOrder $order): PurchaseOrder
    {
        /** @var PurchaseOrder $locked */
        $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->getKey());

        return $locked;
    }

    private function load(PurchaseOrder $order): PurchaseOrder
    {
        return $order->refresh()->load([
            'supplier',
            'warehouse',
            'purchaseRequest',
            'items.item',
            'items.unit',
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
