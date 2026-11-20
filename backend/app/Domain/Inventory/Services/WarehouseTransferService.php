<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Accounting\Services\PeriodGuard;
use App\Domain\Core\Services\AuditTrailService;
use App\Domain\Core\Services\DocumentSequenceService;
use App\Domain\Inventory\Models\WarehouseTransfer;
use App\Domain\Inventory\Models\WarehouseTransferItem;
use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Unit;
use App\Domain\Master\Models\Warehouse;
use App\Domain\Master\Services\UnitConversionService;
use App\Exceptions\ApiException;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Transfer antar gudang.
 *
 * Approve mengeluarkan stok dari gudang asal pada average cost asal, lalu
 * menerima jumlah dan nilai yang sama di gudang tujuan. Total nilai
 * persediaan tidak berubah dan tidak ada jurnal.
 */
final class WarehouseTransferService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly PeriodGuard $periods,
        private readonly UnitConversionService $units,
        private readonly StockService $stock,
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): WarehouseTransfer
    {
        return DB::transaction(function () use ($data): WarehouseTransfer {
            $day = $this->day($data['doc_date']);
            $period = $this->periods->assertOpen($day);
            $fromId = (int) $data['from_warehouse_id'];
            $toId = (int) $data['to_warehouse_id'];
            $this->assertDifferentWarehouses($fromId, $toId);
            Warehouse::query()->findOrFail($fromId);
            Warehouse::query()->findOrFail($toId);

            /** @var WarehouseTransfer $transfer */
            $transfer = WarehouseTransfer::query()->create([
                'doc_no' => $this->sequences->next('TRF', $day),
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'from_warehouse_id' => $fromId,
                'to_warehouse_id' => $toId,
                'status' => WarehouseTransfer::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->actorId(),
            ]);

            $this->syncItems($transfer, $data['items'] ?? []);

            return $this->load($transfer);
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(WarehouseTransfer $transfer, array $data): WarehouseTransfer
    {
        return DB::transaction(function () use ($transfer, $data): WarehouseTransfer {
            $locked = $this->lock($transfer);

            if ($locked->status !== WarehouseTransfer::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya transfer draft yang boleh diubah.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $day = array_key_exists('doc_date', $data)
                ? $this->day($data['doc_date'])
                : $this->day($locked->doc_date);
            $period = $this->periods->assertOpen($day);

            $fromId = (int) ($data['from_warehouse_id'] ?? $locked->from_warehouse_id);
            $toId = (int) ($data['to_warehouse_id'] ?? $locked->to_warehouse_id);
            $this->assertDifferentWarehouses($fromId, $toId);
            Warehouse::query()->findOrFail($fromId);
            Warehouse::query()->findOrFail($toId);

            $locked->forceFill([
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'from_warehouse_id' => $fromId,
                'to_warehouse_id' => $toId,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
            ])->save();

            if (array_key_exists('items', $data)) {
                $this->syncItems($locked, $data['items']);
            }

            return $this->load($locked);
        }, 5);
    }

    public function deleteDraft(WarehouseTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer): void {
            $locked = $this->lock($transfer);

            if ($locked->status !== WarehouseTransfer::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya transfer draft yang boleh dihapus.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $locked->items()->delete();
            $locked->delete();
        }, 5);
    }

    public function approve(WarehouseTransfer $transfer): WarehouseTransfer
    {
        return DB::transaction(function () use ($transfer): WarehouseTransfer {
            $locked = $this->lock($transfer);

            if ($locked->status === WarehouseTransfer::STATUS_APPROVED) {
                return $this->load($locked);
            }

            if ($locked->status !== WarehouseTransfer::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Transfer tidak dapat disetujui dari status ini.',
                    'DOCUMENT_STATE_INVALID',
                );
            }

            $day = $this->day($locked->doc_date);
            $this->periods->assertOpen($day, true);

            /** @var \Illuminate\Support\Collection<int, WarehouseTransferItem> $items */
            $items = $locked->items()
                ->orderBy('item_id')
                ->orderBy('id')
                ->get();

            if ($items->isEmpty()) {
                throw ApiException::unprocessable(
                    'Transfer tidak memiliki baris item.',
                    'DOCUMENT_EMPTY',
                );
            }

            $pairs = [];

            foreach ($items as $item) {
                $pairs[] = ['item_id' => $item->item_id, 'warehouse_id' => $locked->from_warehouse_id];
                $pairs[] = ['item_id' => $item->item_id, 'warehouse_id' => $locked->to_warehouse_id];
            }

            $this->stock->lockBalances($pairs);

            foreach ($items as $item) {
                $ref = [
                    'type' => 'TRF',
                    'id' => (int) $locked->getKey(),
                    'line_id' => (int) $item->getKey(),
                ];

                $issued = $this->stock->issueWithValue(
                    (int) $item->item_id,
                    (int) $locked->from_warehouse_id,
                    (string) $item->qty_base,
                    $ref,
                    $day,
                );

                $this->stock->receive(
                    (int) $item->item_id,
                    (int) $locked->to_warehouse_id,
                    (string) $item->qty_base,
                    $issued['unit_cost'],
                    $ref,
                    $day,
                    null,
                    $issued['total_value'],
                );

                $item->forceFill([
                    'unit_cost_base' => $issued['unit_cost'],
                    'total_value' => $issued['total_value'],
                ])->save();
            }

            $locked->forceFill([
                'status' => WarehouseTransfer::STATUS_APPROVED,
                'approved_by' => $this->actorId(),
                'approved_at' => now(),
            ])->save();

            $this->audit->record($locked, 'approved', [
                'status' => WarehouseTransfer::STATUS_DRAFT,
            ], [
                'status' => WarehouseTransfer::STATUS_APPROVED,
                'doc_no' => $locked->doc_no,
            ]);

            return $this->load($locked);
        }, 5);
    }

    public function disapprove(WarehouseTransfer $transfer): WarehouseTransfer
    {
        return DB::transaction(function () use ($transfer): WarehouseTransfer {
            $locked = $this->lock($transfer);

            if ($locked->status !== WarehouseTransfer::STATUS_APPROVED) {
                throw ApiException::conflict(
                    'Hanya transfer approved yang dapat dibatalkan.',
                    'DOCUMENT_NOT_APPROVED',
                );
            }

            $day = $this->day($locked->doc_date);
            $this->periods->assertOpen($day, true);

            $ref = ['type' => 'TRF', 'id' => (int) $locked->getKey()];

            if ($this->stock->hasActiveMovements($ref)) {
                $this->stock->reverse($ref, $day);
            }

            $locked->items()->update([
                'unit_cost_base' => null,
                'total_value' => null,
            ]);

            $locked->forceFill([
                'status' => WarehouseTransfer::STATUS_DRAFT,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            $this->audit->record($locked, 'disapproved', [
                'status' => WarehouseTransfer::STATUS_APPROVED,
            ], [
                'status' => WarehouseTransfer::STATUS_DRAFT,
                'doc_no' => $locked->doc_no,
            ]);

            return $this->load($locked);
        }, 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(WarehouseTransfer $transfer, array $items): void
    {
        $transfer->items()->delete();

        $line = 1;

        foreach ($items as $row) {
            /** @var Item $item */
            $item = Item::query()->findOrFail((int) $row['item_id']);
            /** @var Unit $unit */
            $unit = Unit::query()->findOrFail((int) $row['unit_id']);

            $qty = Decimal::round((string) $row['qty'], Decimal::QTY_SCALE);
            $qtyBase = $this->units->toBase($item, $unit, $qty);

            $transfer->items()->create([
                'item_id' => $item->getKey(),
                'unit_id' => $unit->getKey(),
                'line_no' => $line++,
                'qty' => $qty,
                'qty_base' => $qtyBase,
            ]);
        }
    }

    private function assertDifferentWarehouses(int $fromId, int $toId): void
    {
        if ($fromId === $toId) {
            throw ApiException::unprocessable(
                'Gudang asal dan gudang tujuan harus berbeda.',
                'SAME_WAREHOUSE_TRANSFER',
                ['to_warehouse_id' => ['Gudang tujuan harus berbeda dari gudang asal.']],
            );
        }
    }

    private function lock(WarehouseTransfer $transfer): WarehouseTransfer
    {
        /** @var WarehouseTransfer $locked */
        $locked = WarehouseTransfer::query()
            ->lockForUpdate()
            ->findOrFail($transfer->getKey());

        return $locked;
    }

    private function load(WarehouseTransfer $transfer): WarehouseTransfer
    {
        return $transfer->refresh()->load([
            'fromWarehouse',
            'toWarehouse',
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
