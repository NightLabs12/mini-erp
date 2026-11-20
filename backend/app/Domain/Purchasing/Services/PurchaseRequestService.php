<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Domain\Accounting\Services\PeriodGuard;
use App\Domain\Core\Services\AuditTrailService;
use App\Domain\Core\Services\DocumentSequenceService;
use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Supplier;
use App\Domain\Master\Models\Unit;
use App\Domain\Master\Models\Warehouse;
use App\Domain\Master\Services\UnitConversionService;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseRequest;
use App\Exceptions\ApiException;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Request: dokumen permintaan tanpa dampak stok/jurnal.
 *
 * PR yang sudah approved dapat dikonversi menjadi PO dengan menyalin baris.
 * Setelah dikonversi, PR ditutup agar tidak dikonversi dua kali.
 */
final class PurchaseRequestService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly PeriodGuard $periods,
        private readonly UnitConversionService $units,
        private readonly PurchaseOrderService $orders,
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PurchaseRequest
    {
        return DB::transaction(function () use ($data): PurchaseRequest {
            $day = $this->day($data['doc_date']);
            $period = $this->periods->assertOpen($day);
            $warehouseId = (int) $data['warehouse_id'];
            Warehouse::query()->findOrFail($warehouseId);
            $supplierId = $this->nullableId($data['supplier_id'] ?? null);

            if ($supplierId !== null) {
                Supplier::query()->findOrFail($supplierId);
            }

            /** @var PurchaseRequest $request */
            $request = PurchaseRequest::query()->create([
                'doc_no' => $this->sequences->next('PR', $day),
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'supplier_id' => $supplierId,
                'warehouse_id' => $warehouseId,
                'status' => PurchaseRequest::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->actorId(),
            ]);

            $this->syncItems($request, $data['items'] ?? []);

            return $this->load($request);
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PurchaseRequest $request, array $data): PurchaseRequest
    {
        return DB::transaction(function () use ($request, $data): PurchaseRequest {
            $locked = $this->lock($request);

            if ($locked->status !== PurchaseRequest::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya purchase request draft yang boleh diubah.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $day = array_key_exists('doc_date', $data)
                ? $this->day($data['doc_date'])
                : $this->day($locked->doc_date);
            $period = $this->periods->assertOpen($day);
            $warehouseId = (int) ($data['warehouse_id'] ?? $locked->warehouse_id);
            Warehouse::query()->findOrFail($warehouseId);
            $supplierId = array_key_exists('supplier_id', $data)
                ? $this->nullableId($data['supplier_id'])
                : $locked->supplier_id;

            if ($supplierId !== null) {
                Supplier::query()->findOrFail($supplierId);
            }

            $locked->forceFill([
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'supplier_id' => $supplierId,
                'warehouse_id' => $warehouseId,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
            ])->save();

            if (array_key_exists('items', $data)) {
                $this->syncItems($locked, $data['items']);
            }

            return $this->load($locked);
        }, 5);
    }

    public function deleteDraft(PurchaseRequest $request): void
    {
        DB::transaction(function () use ($request): void {
            $locked = $this->lock($request);

            if ($locked->status !== PurchaseRequest::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya purchase request draft yang boleh dihapus.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $locked->items()->delete();
            $locked->delete();
        }, 5);
    }

    public function approve(PurchaseRequest $request): PurchaseRequest
    {
        return DB::transaction(function () use ($request): PurchaseRequest {
            $locked = $this->lock($request);

            if ($locked->status === PurchaseRequest::STATUS_APPROVED) {
                return $this->load($locked);
            }

            if ($locked->status !== PurchaseRequest::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Purchase request tidak dapat disetujui dari status ini.',
                    'DOCUMENT_STATE_INVALID',
                );
            }

            $this->periods->assertOpen($this->day($locked->doc_date), true);

            if ($locked->items()->count() === 0) {
                throw ApiException::unprocessable(
                    'Purchase request tidak memiliki baris item.',
                    'DOCUMENT_EMPTY',
                );
            }

            $locked->forceFill([
                'status' => PurchaseRequest::STATUS_APPROVED,
                'approved_by' => $this->actorId(),
                'approved_at' => now(),
            ])->save();

            $this->audit->record($locked, 'approved', ['status' => PurchaseRequest::STATUS_DRAFT], [
                'status' => PurchaseRequest::STATUS_APPROVED,
                'doc_no' => $locked->doc_no,
            ]);

            return $this->load($locked);
        }, 5);
    }

    public function disapprove(PurchaseRequest $request): PurchaseRequest
    {
        return DB::transaction(function () use ($request): PurchaseRequest {
            $locked = $this->lock($request);

            if ($locked->status !== PurchaseRequest::STATUS_APPROVED) {
                throw ApiException::conflict(
                    'Hanya purchase request approved yang dapat dibatalkan.',
                    'DOCUMENT_NOT_APPROVED',
                );
            }

            $this->periods->assertOpen($this->day($locked->doc_date), true);

            $hasActiveOrder = PurchaseOrder::query()
                ->where('purchase_request_id', $locked->getKey())
                ->whereNotIn('status', [PurchaseOrder::STATUS_CANCELLED])
                ->whereNull('deleted_at')
                ->exists();

            if ($hasActiveOrder) {
                throw ApiException::conflict(
                    'Purchase request sudah dikonversi menjadi purchase order aktif.',
                    'DOCUMENT_HAS_CHILDREN',
                    ['references' => ['purchase_orders']],
                );
            }

            $locked->forceFill([
                'status' => PurchaseRequest::STATUS_DRAFT,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            $this->audit->record($locked, 'disapproved', ['status' => PurchaseRequest::STATUS_APPROVED], [
                'status' => PurchaseRequest::STATUS_DRAFT,
                'doc_no' => $locked->doc_no,
            ]);

            return $this->load($locked);
        }, 5);
    }

    public function close(PurchaseRequest $request): PurchaseRequest
    {
        return DB::transaction(function () use ($request): PurchaseRequest {
            $locked = $this->lock($request);

            if ($locked->status !== PurchaseRequest::STATUS_APPROVED) {
                throw ApiException::conflict(
                    'Hanya purchase request approved yang dapat ditutup.',
                    'DOCUMENT_NOT_APPROVED',
                );
            }

            $locked->forceFill(['status' => PurchaseRequest::STATUS_CLOSED])->save();

            $this->audit->record($locked, 'closed', ['status' => PurchaseRequest::STATUS_APPROVED], [
                'status' => PurchaseRequest::STATUS_CLOSED,
                'doc_no' => $locked->doc_no,
            ]);

            return $this->load($locked);
        }, 5);
    }

    /**
     * Konversi PR approved menjadi PO. Baris disalin; supplier/warehouse
     * diambil dari PR atau payload bila PR belum punya supplier.
     *
     * @param  array<string, mixed>  $data
     */
    public function convertToOrder(PurchaseRequest $request, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($request, $data): PurchaseOrder {
            $locked = $this->lock($request);

            if ($locked->status !== PurchaseRequest::STATUS_APPROVED) {
                throw ApiException::conflict(
                    'Hanya purchase request approved yang dapat dikonversi.',
                    'DOCUMENT_NOT_APPROVED',
                );
            }

            $supplierId = $this->nullableId($data['supplier_id'] ?? $locked->supplier_id);

            if ($supplierId === null) {
                throw ApiException::unprocessable(
                    'Supplier wajib diisi untuk membuat purchase order.',
                    'SUPPLIER_REQUIRED',
                    ['supplier_id' => ['Pilih supplier terlebih dahulu.']],
                );
            }

            $items = $locked->items()->get()->map(static fn ($item): array => [
                'item_id' => (int) $item->item_id,
                'unit_id' => (int) $item->unit_id,
                'qty' => (string) $item->qty,
                'unit_price' => (string) ($data['unit_price'] ?? '0'),
                'discount_pct' => '0',
            ])->all();

            $order = $this->orders->create([
                'doc_date' => $data['doc_date'] ?? $locked->doc_date->toDateString(),
                'purchase_request_id' => $locked->getKey(),
                'supplier_id' => $supplierId,
                'warehouse_id' => $locked->warehouse_id,
                'expected_date' => $data['expected_date'] ?? null,
                'tax_rate' => $data['tax_rate'] ?? null,
                'notes' => $data['notes'] ?? $locked->notes,
                'items' => $data['items'] ?? $items,
            ]);

            $locked->forceFill(['status' => PurchaseRequest::STATUS_CLOSED])->save();

            $this->audit->record($locked, 'closed', ['status' => PurchaseRequest::STATUS_APPROVED], [
                'status' => PurchaseRequest::STATUS_CLOSED,
                'doc_no' => $locked->doc_no,
                'purchase_order_id' => $order->getKey(),
            ]);

            return $order;
        }, 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(PurchaseRequest $request, array $items): void
    {
        $request->items()->delete();

        $line = 1;

        foreach ($items as $row) {
            /** @var Item $item */
            $item = Item::query()->findOrFail((int) $row['item_id']);
            /** @var Unit $unit */
            $unit = Unit::query()->findOrFail((int) $row['unit_id']);

            $qty = Decimal::round((string) $row['qty'], Decimal::QTY_SCALE);
            $qtyBase = $this->units->toBase($item, $unit, $qty);

            $request->items()->create([
                'item_id' => $item->getKey(),
                'unit_id' => $unit->getKey(),
                'line_no' => $line++,
                'qty' => $qty,
                'qty_base' => $qtyBase,
            ]);
        }
    }

    private function lock(PurchaseRequest $request): PurchaseRequest
    {
        /** @var PurchaseRequest $locked */
        $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($request->getKey());

        return $locked;
    }

    private function load(PurchaseRequest $request): PurchaseRequest
    {
        return $request->refresh()->load(['supplier', 'warehouse', 'items.item', 'items.unit']);
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
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
