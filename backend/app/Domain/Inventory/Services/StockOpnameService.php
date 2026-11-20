<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Accounting\Models\Journal;
use App\Domain\Accounting\Services\AccountMapper;
use App\Domain\Accounting\Services\JournalService;
use App\Domain\Accounting\Services\PeriodGuard;
use App\Domain\Core\Services\AuditTrailService;
use App\Domain\Core\Services\DocumentSequenceService;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameItem;
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
 * Stock opname.
 *
 * Draft menyimpan snapshot sistem untuk tampilan. Saat approve, saldo terkini
 * dibaca ulang di bawah lock sehingga hasil akhir selalu sama dengan qty
 * hitung fisik. Selisih minus/plus membentuk mutasi stok dan jurnal
 * penyesuaian lewat JournalService (SPEC 3.8).
 */
final class StockOpnameService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly PeriodGuard $periods,
        private readonly UnitConversionService $units,
        private readonly StockService $stock,
        private readonly JournalService $journals,
        private readonly AccountMapper $accounts,
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): StockOpname
    {
        return DB::transaction(function () use ($data): StockOpname {
            $day = $this->day($data['doc_date']);
            $period = $this->periods->assertOpen($day);
            $warehouseId = (int) $data['warehouse_id'];
            Warehouse::query()->findOrFail($warehouseId);

            /** @var StockOpname $opname */
            $opname = StockOpname::query()->create([
                'doc_no' => $this->sequences->next('OPN', $day),
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'warehouse_id' => $warehouseId,
                'status' => StockOpname::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->actorId(),
            ]);

            $this->syncItems($opname, $data['items'] ?? []);

            return $this->load($opname);
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(StockOpname $opname, array $data): StockOpname
    {
        return DB::transaction(function () use ($opname, $data): StockOpname {
            $locked = $this->lock($opname);

            if ($locked->status !== StockOpname::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya stock opname draft yang boleh diubah.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $day = array_key_exists('doc_date', $data)
                ? $this->day($data['doc_date'])
                : $this->day($locked->doc_date);
            $period = $this->periods->assertOpen($day);

            $warehouseId = (int) ($data['warehouse_id'] ?? $locked->warehouse_id);
            Warehouse::query()->findOrFail($warehouseId);
            $warehouseChanged = $warehouseId !== (int) $locked->warehouse_id;

            $locked->forceFill([
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'warehouse_id' => $warehouseId,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
            ])->save();

            if (array_key_exists('items', $data)) {
                $this->syncItems($locked, $data['items']);
            } elseif ($warehouseChanged) {
                $this->refreshSnapshots($locked);
            }

            return $this->load($locked);
        }, 5);
    }

    public function deleteDraft(StockOpname $opname): void
    {
        DB::transaction(function () use ($opname): void {
            $locked = $this->lock($opname);

            if ($locked->status !== StockOpname::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya stock opname draft yang boleh dihapus.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $locked->items()->delete();
            $locked->delete();
        }, 5);
    }

    public function approve(StockOpname $opname): StockOpname
    {
        return DB::transaction(function () use ($opname): StockOpname {
            $locked = $this->lock($opname);

            if ($locked->status === StockOpname::STATUS_APPROVED) {
                return $this->load($locked);
            }

            if ($locked->status !== StockOpname::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Stock opname tidak dapat disetujui dari status ini.',
                    'DOCUMENT_STATE_INVALID',
                );
            }

            $day = $this->day($locked->doc_date);
            $this->periods->assertOpen($day, true);

            /** @var \Illuminate\Support\Collection<int, StockOpnameItem> $items */
            $items = $locked->items()
                ->orderBy('item_id')
                ->orderBy('id')
                ->get();

            if ($items->isEmpty()) {
                throw ApiException::unprocessable(
                    'Stock opname tidak memiliki baris item.',
                    'DOCUMENT_EMPTY',
                );
            }

            $pairs = [];

            foreach ($items as $row) {
                $pairs[] = [
                    'item_id' => (int) $row->item_id,
                    'warehouse_id' => (int) $locked->warehouse_id,
                ];
            }

            $this->stock->lockBalances($pairs);

            /** @var list<array{item: Item, diff: string, value: string}> $adjustments */
            $adjustments = [];

            foreach ($items as $row) {
                /** @var Item $item */
                $item = Item::query()->findOrFail((int) $row->item_id);

                $balance = $this->stock->balance(
                    (int) $row->item_id,
                    (int) $locked->warehouse_id,
                );

                $system = $this->qty((string) $balance->qty_base);
                $counted = $this->qty((string) $row->counted_qty_base);
                $diff = Decimal::sub($counted, $system, Decimal::QTY_SCALE);
                $avg = $this->qty((string) $balance->avg_cost);
                $value = '0.00';

                $ref = [
                    'type' => 'OPN',
                    'id' => (int) $locked->getKey(),
                    'line_id' => (int) $row->getKey(),
                ];

                if (Decimal::compare($diff, '0', Decimal::QTY_SCALE) > 0) {
                    $ledger = $this->stock->receive(
                        (int) $row->item_id,
                        (int) $locked->warehouse_id,
                        $diff,
                        $avg,
                        $ref,
                        $day,
                    );

                    $value = $this->money((string) $ledger->total_value);
                } elseif (Decimal::compare($diff, '0', Decimal::QTY_SCALE) < 0) {
                    $issued = $this->stock->issueWithValue(
                        (int) $row->item_id,
                        (int) $locked->warehouse_id,
                        Decimal::abs($diff, Decimal::QTY_SCALE),
                        $ref,
                        $day,
                    );

                    $value = $this->money($issued['total_value']);
                }

                $signedValue = Decimal::compare($diff, '0', Decimal::QTY_SCALE) < 0
                    ? Decimal::mul($value, '-1', Decimal::MONEY_SCALE)
                    : $value;

                $row->forceFill([
                    'system_qty_base' => $system,
                    'diff_qty_base' => $diff,
                    'unit_cost' => $avg,
                    'total_value' => $signedValue,
                ])->save();

                if (Decimal::compare($diff, '0', Decimal::QTY_SCALE) !== 0) {
                    $adjustments[] = [
                        'item' => $item,
                        'diff' => $diff,
                        'value' => $value,
                    ];
                }
            }

            $journal = $this->postAdjustmentJournal($locked, $adjustments, $day);

            $locked->forceFill([
                'status' => StockOpname::STATUS_APPROVED,
                'journal_id' => $journal?->getKey(),
                'approved_by' => $this->actorId(),
                'approved_at' => now(),
            ])->save();

            $this->audit->record($locked, 'approved', [
                'status' => StockOpname::STATUS_DRAFT,
            ], [
                'status' => StockOpname::STATUS_APPROVED,
                'doc_no' => $locked->doc_no,
                'journal_id' => $locked->journal_id,
            ]);

            return $this->load($locked);
        }, 5);
    }

    public function disapprove(StockOpname $opname): StockOpname
    {
        return DB::transaction(function () use ($opname): StockOpname {
            $locked = $this->lock($opname);

            if ($locked->status !== StockOpname::STATUS_APPROVED) {
                throw ApiException::conflict(
                    'Hanya stock opname approved yang dapat dibatalkan.',
                    'DOCUMENT_NOT_APPROVED',
                );
            }

            $day = $this->day($locked->doc_date);
            $this->periods->assertOpen($day, true);

            $ref = ['type' => 'OPN', 'id' => (int) $locked->getKey()];

            if ($this->stock->hasActiveMovements($ref)) {
                $this->stock->reverse($ref, $day);
            }

            $journal = $locked->journal_id !== null
                ? Journal::query()->find($locked->journal_id)
                : Journal::query()
                    ->where('source_type', 'stock_opname')
                    ->where('source_id', $locked->getKey())
                    ->where('status', Journal::STATUS_POSTED)
                    ->latest('id')
                    ->first();

            if ($journal instanceof Journal && $journal->status === Journal::STATUS_POSTED) {
                $this->journals->reverse($journal);
            }

            $locked->items()->update([
                'unit_cost' => null,
                'total_value' => null,
            ]);

            $locked->forceFill([
                'status' => StockOpname::STATUS_DRAFT,
                'journal_id' => null,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            $this->audit->record($locked, 'disapproved', [
                'status' => StockOpname::STATUS_APPROVED,
            ], [
                'status' => StockOpname::STATUS_DRAFT,
                'doc_no' => $locked->doc_no,
            ]);

            return $this->load($locked);
        }, 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(StockOpname $opname, array $items): void
    {
        $opname->items()->delete();

        $line = 1;

        foreach ($items as $row) {
            /** @var Item $item */
            $item = Item::query()->findOrFail((int) $row['item_id']);
            /** @var Unit $unit */
            $unit = Unit::query()->findOrFail((int) $row['unit_id']);

            $counted = $this->units->toBase($item, $unit, (string) $row['counted_qty']);
            $balance = $this->stock->balance($item, (int) $opname->warehouse_id);
            $system = $this->qty((string) $balance->qty_base);
            $diff = Decimal::sub($counted, $system, Decimal::QTY_SCALE);
            $avg = $this->qty((string) $balance->avg_cost);
            $value = Decimal::round(
                Decimal::mul($diff, $avg, Decimal::QTY_SCALE + 2),
                Decimal::MONEY_SCALE,
            );

            $opname->items()->create([
                'item_id' => $item->getKey(),
                'unit_id' => $unit->getKey(),
                'line_no' => $line++,
                'system_qty_base' => $system,
                'counted_qty_base' => $counted,
                'diff_qty_base' => $diff,
                'unit_cost' => $avg,
                'total_value' => $value,
            ]);
        }
    }

    private function refreshSnapshots(StockOpname $opname): void
    {
        foreach ($opname->items()->get() as $row) {
            $balance = $this->stock->balance(
                (int) $row->item_id,
                (int) $opname->warehouse_id,
            );

            $system = $this->qty((string) $balance->qty_base);
            $diff = Decimal::sub((string) $row->counted_qty_base, $system, Decimal::QTY_SCALE);
            $avg = $this->qty((string) $balance->avg_cost);
            $value = Decimal::round(
                Decimal::mul($diff, $avg, Decimal::QTY_SCALE + 2),
                Decimal::MONEY_SCALE,
            );

            $row->forceFill([
                'system_qty_base' => $system,
                'diff_qty_base' => $diff,
                'unit_cost' => $avg,
                'total_value' => $value,
            ])->save();
        }
    }

    /**
     * @param  list<array{item: Item, diff: string, value: string}>  $adjustments
     */
    private function postAdjustmentJournal(
        StockOpname $opname,
        array $adjustments,
        CarbonImmutable $day,
    ): ?Journal {
        if ($adjustments === []) {
            return null;
        }

        $adjustmentAccountId = (int) $this->accounts
            ->accountFor(AccountMapper::STOCK_ADJUSTMENT)
            ->getKey();

        /** @var array<int, string> $net */
        $net = [];

        foreach ($adjustments as $adjustment) {
            $value = $this->money($adjustment['value']);

            if (Decimal::compare($value, '0', Decimal::MONEY_SCALE) <= 0) {
                continue;
            }

            $inventoryAccountId = (int) $this->accounts
                ->inventoryFor($adjustment['item'])
                ->getKey();

            if (Decimal::compare($adjustment['diff'], '0', Decimal::QTY_SCALE) < 0) {
                $this->accumulate($net, $adjustmentAccountId, $value);
                $this->accumulate($net, $inventoryAccountId, Decimal::mul($value, '-1', Decimal::MONEY_SCALE));

                continue;
            }

            $this->accumulate($net, $inventoryAccountId, $value);
            $this->accumulate($net, $adjustmentAccountId, Decimal::mul($value, '-1', Decimal::MONEY_SCALE));
        }

        ksort($net);

        $lines = [];

        foreach ($net as $accountId => $amount) {
            if (Decimal::compare($amount, '0', Decimal::MONEY_SCALE) > 0) {
                $lines[] = ['account_id' => $accountId, 'debit' => $this->money($amount)];

                continue;
            }

            if (Decimal::compare($amount, '0', Decimal::MONEY_SCALE) < 0) {
                $lines[] = [
                    'account_id' => $accountId,
                    'credit' => $this->money(Decimal::mul($amount, '-1', Decimal::MONEY_SCALE)),
                ];
            }
        }

        if (count($lines) < 2) {
            return null;
        }

        return $this->journals->post(
            $lines,
            ['type' => 'stock_opname', 'id' => (int) $opname->getKey()],
            $day,
            "Penyesuaian stok opname {$opname->doc_no}",
        );
    }

    /**
     * @param  array<int, string>  $net
     */
    private function accumulate(array &$net, int $accountId, string $amount): void
    {
        $net[$accountId] = Decimal::add($net[$accountId] ?? '0.00', $amount, Decimal::MONEY_SCALE);
    }

    private function lock(StockOpname $opname): StockOpname
    {
        /** @var StockOpname $locked */
        $locked = StockOpname::query()
            ->lockForUpdate()
            ->findOrFail($opname->getKey());

        return $locked;
    }

    private function load(StockOpname $opname): StockOpname
    {
        return $opname->refresh()->load([
            'warehouse',
            'items.item',
            'items.unit',
            'journal',
        ]);
    }

    private function day(CarbonInterface|string $date): CarbonImmutable
    {
        return $date instanceof CarbonInterface
            ? CarbonImmutable::parse($date->toDateString())
            : CarbonImmutable::parse($date);
    }

    private function qty(string|int $value): string
    {
        return Decimal::round($value, Decimal::QTY_SCALE);
    }

    private function money(string|int $value): string
    {
        return Decimal::round($value, Decimal::MONEY_SCALE);
    }

    private function actorId(): ?int
    {
        $user = app()->bound('request') ? request()->user() : null;
        $id = $user?->getAuthIdentifier() ?? Auth::id();

        return $id === null ? null : (int) $id;
    }
}
