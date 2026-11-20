<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Accounting\Services\PeriodGuard;
use App\Domain\Core\Models\User;
use App\Domain\Core\Services\AuditTrailService;
use App\Domain\Core\Services\DocumentSequenceService;
use App\Domain\Core\Services\SettingService;
use App\Domain\Master\Models\Customer;
use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\SellingPrice;
use App\Domain\Master\Models\Unit;
use App\Domain\Master\Models\Warehouse;
use App\Domain\Master\Services\SellingPriceResolver;
use App\Domain\Master\Services\UnitConversionService;
use App\Domain\Sales\Models\DeliveryOrder;
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
 * Sales Order.
 *
 * - Harga jual diambil otomatis dari selling_prices. Override harga hanya
 *   boleh bila pengguna memiliki permission sales.sales-order.override-price.
 * - Credit limit diperiksa sesuai setting credit_limit_enforcement
 *   (off/warn/block). Nilai limit 0 dianggap tanpa batas.
 * - SO otomatis closed saat seluruh qty base dikirim; penutupan manual
 *   ditandai closed_manually.
 */
final class SalesOrderService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly PeriodGuard $periods,
        private readonly UnitConversionService $units,
        private readonly SellingPriceResolver $prices,
        private readonly SettingService $settings,
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): SalesOrder
    {
        return DB::transaction(function () use ($data): SalesOrder {
            $day = $this->day($data['doc_date']);
            $period = $this->periods->assertOpen($day);
            $customerId = (int) $data['customer_id'];
            $warehouseId = (int) $data['warehouse_id'];
            Customer::query()->findOrFail($customerId);
            Warehouse::query()->findOrFail($warehouseId);

            /** @var SalesOrder $order */
            $order = SalesOrder::query()->create([
                'doc_no' => $this->sequences->next('SO', $day),
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'customer_id' => $customerId,
                'warehouse_id' => $warehouseId,
                'status' => SalesOrder::STATUS_DRAFT,
                'tax_rate' => $this->taxRate($data['tax_rate'] ?? null),
                'closed_manually' => false,
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->actorId(),
            ]);

            $this->syncItems($order, $customerId, $day, $data['items'] ?? []);
            $this->recalculate($order);
            $order->creditWarning = $this->creditWarning($order, true);

            return $this->load($order);
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SalesOrder $order, array $data): SalesOrder
    {
        return DB::transaction(function () use ($order, $data): SalesOrder {
            $locked = $this->lock($order);

            if ($locked->status !== SalesOrder::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya sales order draft yang boleh diubah.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $day = array_key_exists('doc_date', $data)
                ? $this->day($data['doc_date'])
                : $this->day($locked->doc_date);
            $period = $this->periods->assertOpen($day);
            $customerId = (int) ($data['customer_id'] ?? $locked->customer_id);
            $warehouseId = (int) ($data['warehouse_id'] ?? $locked->warehouse_id);
            Customer::query()->findOrFail($customerId);
            Warehouse::query()->findOrFail($warehouseId);

            $locked->forceFill([
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'customer_id' => $customerId,
                'warehouse_id' => $warehouseId,
                'tax_rate' => array_key_exists('tax_rate', $data)
                    ? $this->taxRate($data['tax_rate'])
                    : (string) $locked->tax_rate,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
            ])->save();

            if (array_key_exists('items', $data)) {
                $this->syncItems($locked, $customerId, $day, $data['items']);
            }

            $this->recalculate($locked);
            $locked->creditWarning = $this->creditWarning($locked, true);

            return $this->load($locked);
        }, 5);
    }

    public function deleteDraft(SalesOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $locked = $this->lock($order);

            if ($locked->status !== SalesOrder::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya sales order draft yang boleh dihapus.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $locked->items()->delete();
            $locked->delete();
        }, 5);
    }

    public function approve(SalesOrder $order): SalesOrder
    {
        return DB::transaction(function () use ($order): SalesOrder {
            $locked = $this->lock($order);

            if ($locked->status === SalesOrder::STATUS_APPROVED) {
                return $this->load($locked);
            }

            if ($locked->status !== SalesOrder::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Sales order tidak dapat disetujui dari status ini.',
                    'DOCUMENT_STATE_INVALID',
                );
            }

            $this->periods->assertOpen($this->day($locked->doc_date), true);

            if ($locked->items()->count() === 0) {
                throw ApiException::unprocessable(
                    'Sales order tidak memiliki baris item.',
                    'DOCUMENT_EMPTY',
                );
            }

            $this->recalculate($locked);
            $warning = $this->creditWarning($locked, true);

            $locked->forceFill([
                'status' => SalesOrder::STATUS_APPROVED,
                'approved_by' => $this->actorId(),
                'approved_at' => now(),
            ])->save();

            $this->audit->record($locked, 'approved', ['status' => SalesOrder::STATUS_DRAFT], [
                'status' => SalesOrder::STATUS_APPROVED,
                'doc_no' => $locked->doc_no,
                'total' => $locked->total,
            ]);

            $loaded = $this->load($locked);
            $loaded->creditWarning = $warning;

            return $loaded;
        }, 5);
    }

    public function close(SalesOrder $order): SalesOrder
    {
        return DB::transaction(function () use ($order): SalesOrder {
            $locked = $this->lock($order);

            if ($locked->status !== SalesOrder::STATUS_APPROVED) {
                throw ApiException::conflict(
                    'Hanya sales order approved yang dapat ditutup.',
                    'DOCUMENT_NOT_APPROVED',
                );
            }

            $locked->forceFill([
                'status' => SalesOrder::STATUS_CLOSED,
                'closed_manually' => true,
            ])->save();

            $this->audit->record($locked, 'closed', ['status' => SalesOrder::STATUS_APPROVED], [
                'status' => SalesOrder::STATUS_CLOSED,
                'doc_no' => $locked->doc_no,
            ]);

            return $this->load($locked);
        }, 5);
    }

    public function disapprove(SalesOrder $order): SalesOrder
    {
        return DB::transaction(function () use ($order): SalesOrder {
            $locked = $this->lock($order);

            if ($locked->status !== SalesOrder::STATUS_APPROVED) {
                throw ApiException::conflict(
                    'Hanya sales order approved yang dapat dibatalkan.',
                    'DOCUMENT_NOT_APPROVED',
                );
            }

            $this->periods->assertOpen($this->day($locked->doc_date), true);

            $hasActiveDelivery = DeliveryOrder::query()
                ->where('sales_order_id', $locked->getKey())
                ->whereIn('status', [DeliveryOrder::STATUS_DRAFT, DeliveryOrder::STATUS_APPROVED])
                ->whereNull('deleted_at')
                ->exists();

            if ($hasActiveDelivery) {
                throw ApiException::conflict(
                    'Sales order masih memiliki delivery order aktif.',
                    'DOCUMENT_HAS_CHILDREN',
                    ['references' => ['delivery_orders']],
                );
            }

            $locked->forceFill([
                'status' => SalesOrder::STATUS_DRAFT,
                'approved_by' => null,
                'approved_at' => null,
                'closed_manually' => false,
            ])->save();

            $this->audit->record($locked, 'disapproved', ['status' => SalesOrder::STATUS_APPROVED], [
                'status' => SalesOrder::STATUS_DRAFT,
                'doc_no' => $locked->doc_no,
            ]);

            return $this->load($locked);
        }, 5);
    }

    /**
     * Menyesuaikan status SO setelah pengiriman berubah.
     */
    public function refreshFulfillment(SalesOrder $order): SalesOrder
    {
        /** @var SalesOrder $fresh */
        $fresh = SalesOrder::query()->findOrFail($order->getKey());

        /** @var Collection<int, SalesOrderItem> $items */
        $items = $fresh->items()->get();

        if ($items->isEmpty()) {
            return $fresh;
        }

        $fullyDelivered = $items->every(
            static fn (SalesOrderItem $item): bool => Decimal::compare(
                (string) $item->delivered_qty_base,
                (string) $item->qty_base,
                Decimal::QTY_SCALE,
            ) >= 0,
        );

        if ($fullyDelivered && $fresh->status === SalesOrder::STATUS_APPROVED) {
            $fresh->forceFill([
                'status' => SalesOrder::STATUS_CLOSED,
                'closed_manually' => false,
            ])->save();
        } elseif (! $fullyDelivered
            && $fresh->status === SalesOrder::STATUS_CLOSED
            && ! $fresh->closed_manually) {
            $fresh->forceFill([
                'status' => SalesOrder::STATUS_APPROVED,
                'closed_manually' => false,
            ])->save();
        }

        return $fresh;
    }

    /**
     * Mengunci header dan seluruh baris SO dengan urutan id agar aman dari
     * deadlock. Dipakai DO saat mengirim stok.
     *
     * @return Collection<int, SalesOrderItem>
     */
    public function lockOrderItems(SalesOrder $order): Collection
    {
        SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());

        /** @var Collection<int, SalesOrderItem> $items */
        $items = SalesOrderItem::query()
            ->with('item')
            ->where('sales_order_id', $order->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(SalesOrder $order, int $customerId, CarbonImmutable $day, array $items): void
    {
        $order->items()->delete();

        $line = 1;
        $canOverride = $this->canOverridePrice();

        foreach ($items as $row) {
            /** @var Item $item */
            $item = Item::query()->findOrFail((int) $row['item_id']);
            /** @var Unit $unit */
            $unit = Unit::query()->findOrFail((int) $row['unit_id']);

            $qty = Decimal::round((string) $row['qty'], Decimal::QTY_SCALE);
            $qtyBase = $this->units->toBase($item, $unit, $qty);
            $discount = Decimal::round((string) ($row['discount_pct'] ?? '0'), 2);

            if (Decimal::isNegative($discount) || Decimal::compare($discount, '100', 2) > 0) {
                throw ApiException::unprocessable(
                    'Diskon baris harus antara 0 dan 100 persen.',
                    'SALES_DISCOUNT_INVALID',
                    ['discount_pct' => ['Diskon harus antara 0 dan 100.']],
                );
            }

            $unitPrice = $this->resolvePrice($item, $unit, $customerId, $day, $row, $canOverride);
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
                'delivered_qty_base' => '0.0000',
                'invoiced_qty_base' => '0.0000',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolvePrice(
        Item $item,
        Unit $unit,
        int $customerId,
        CarbonImmutable $day,
        array $row,
        bool $canOverride,
    ): string {
        /** @var SellingPrice|null $resolved */
        $resolved = $this->prices->find((int) $item->getKey(), $customerId, $day, (int) $unit->getKey());
        $provided = array_key_exists('unit_price', $row) && $row['unit_price'] !== null && $row['unit_price'] !== '';

        if (! $provided) {
            if (! $resolved instanceof SellingPrice) {
                throw ApiException::unprocessable(
                    "Harga jual {$item->name} untuk satuan {$unit->code} belum didefinisikan.",
                    'SELLING_PRICE_NOT_FOUND',
                    ['items' => ["Isi harga jual terlebih dahulu untuk {$item->sku}."]],
                );
            }

            return Decimal::round((string) $resolved->price, Decimal::QTY_SCALE);
        }

        $unitPrice = Decimal::round((string) $row['unit_price'], Decimal::QTY_SCALE);

        if (Decimal::isNegative($unitPrice)) {
            throw ApiException::unprocessable(
                'Harga satuan tidak boleh negatif.',
                'SALES_PRICE_INVALID',
                ['unit_price' => ['Harga satuan tidak boleh negatif.']],
            );
        }

        $matchesResolved = $resolved instanceof SellingPrice
            && Decimal::compare($unitPrice, (string) $resolved->price, Decimal::QTY_SCALE) === 0;

        if (! $matchesResolved && ! $canOverride) {
            $expected = $resolved instanceof SellingPrice
                ? Decimal::round((string) $resolved->price, Decimal::QTY_SCALE)
                : 'tidak tersedia';

            throw ApiException::unprocessable(
                "Harga {$unitPrice} berbeda dari harga jual berlaku ({$expected}). "
                .'Override harga membutuhkan permission sales.sales-order.override-price.',
                'PRICE_OVERRIDE_FORBIDDEN',
                ['unit_price' => ['Anda tidak berwenang mengubah harga jual.']],
            );
        }

        return $unitPrice;
    }

    private function recalculate(SalesOrder $order): void
    {
        $subtotal = '0.00';
        $discount = '0.00';

        /** @var Collection<int, SalesOrderItem> $items */
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

    /**
     * Mengembalikan pesan peringatan credit limit, atau null bila aman.
     * Mode block langsung melempar exception.
     */
    private function creditWarning(SalesOrder $order, bool $includeCurrent): ?string
    {
        $mode = strtolower((string) $this->settings->get('credit_limit_enforcement', 'warn'));

        if ($mode === 'off') {
            return null;
        }

        /** @var Customer|null $customer */
        $customer = Customer::query()->find($order->customer_id);

        if (! $customer instanceof Customer) {
            return null;
        }

        $limit = Decimal::round((string) $customer->credit_limit, Decimal::MONEY_SCALE);

        // Limit 0 dianggap tanpa batas kredit.
        if (Decimal::compare($limit, '0', Decimal::MONEY_SCALE) <= 0) {
            return null;
        }

        $outstanding = (string) DB::table('sales_invoices')
            ->where('customer_id', $customer->getKey())
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) AS amount')
            ->value('amount');

        $openOrders = (string) DB::table('sales_orders')
            ->where('customer_id', $customer->getKey())
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->when($order->exists, static fn ($query) => $query->where('id', '!=', $order->getKey()))
            ->selectRaw('COALESCE(SUM(total), 0) AS amount')
            ->value('amount');

        $exposure = Decimal::add($outstanding, $openOrders, Decimal::MONEY_SCALE);

        if ($includeCurrent) {
            $exposure = Decimal::add($exposure, (string) $order->total, Decimal::MONEY_SCALE);
        }

        if (Decimal::compare($exposure, $limit, Decimal::MONEY_SCALE) <= 0) {
            return null;
        }

        $message = "Credit limit {$customer->name} terlampaui: limit {$limit}, "
            ."eksposur {$exposure}.";

        if ($mode === 'block') {
            throw ApiException::unprocessable(
                $message,
                'CREDIT_LIMIT_EXCEEDED',
                ['customer_id' => [$message]],
            );
        }

        return $message;
    }

    private function canOverridePrice(): bool
    {
        $user = app()->bound('request') ? request()->user() : null;
        $user ??= Auth::user();

        return $user instanceof User && $user->can('sales.sales-order.override-price');
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

    private function lock(SalesOrder $order): SalesOrder
    {
        /** @var SalesOrder $locked */
        $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());

        return $locked;
    }

    private function load(SalesOrder $order): SalesOrder
    {
        return $order->refresh()->load([
            'customer',
            'warehouse',
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
