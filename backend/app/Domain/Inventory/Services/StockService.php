<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Accounting\Services\PeriodGuard;
use App\Domain\Core\Services\SettingService;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockLedger;
use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Warehouse;
use App\Exceptions\ApiException;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya pintu mutasi stok (SPEC 3.5 dan 3.6).
 *
 * - Ledger append-only; pembalikan selalu membuat baris baru.
 * - Saldo di-lock FOR UPDATE dengan urutan deterministik item_id lalu
 *   warehouse_id agar tidak deadlock.
 * - Moving average memakai nilai persediaan berjalan. Saat qty habis, total
 *   nilai baris terakhir dikoreksi menjadi saldo nilai sebelumnya sehingga
 *   balance_value selalu konsisten dengan SUM ledger.
 * - Stok negatif ditolak kecuali setting allow_negative_stock=true.
 */
final class StockService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly PeriodGuard $periods,
    ) {}

    /**
     * Menerima stok pada satuan base.
     *
     * $totalValue opsional dipakai transfer agar nilai yang keluar dari gudang
     * asal sama persis dengan nilai yang masuk ke gudang tujuan.
     *
     * @param  array{type?: string, id?: int|string|null, line_id?: int|null}|string  $docRef
     */
    public function receive(
        Item|int $item,
        Warehouse|int $warehouse,
        string|int $qtyBase,
        string|int $unitCost,
        array|string $docRef,
        CarbonInterface|string $date,
        ?int $lineId = null,
        ?string $totalValue = null,
    ): StockLedger {
        $itemModel = $this->item($item);
        $warehouseModel = $this->warehouse($warehouse);
        $qty = $this->positiveQty($qtyBase);
        $cost = $this->nonNegativeCost($unitCost);
        $total = $totalValue === null
            ? Decimal::round(Decimal::mul($qty, $cost, Decimal::QTY_SCALE + 2), Decimal::MONEY_SCALE)
            : $this->nonNegativeMoney($totalValue);

        $ref = $this->docRef($docRef, $lineId);
        $day = $this->day($date);

        return DB::transaction(function () use ($itemModel, $warehouseModel, $qty, $cost, $total, $ref, $day): StockLedger {
            // Mutasi stok adalah posting ke periode. Guard di sini memastikan
            // pemanggil langsung (di luar service dokumen) tidak dapat menulis
            // ke periode yang sudah ditutup.
            $this->periods->assertOpen($day, true);
            $balance = $this->lockBalance((int) $itemModel->getKey(), (int) $warehouseModel->getKey());

            return $this->persistMovement(
                $balance,
                (int) $itemModel->getKey(),
                (int) $warehouseModel->getKey(),
                $day,
                $qty,
                '0.0000',
                $cost,
                $total,
                $ref,
                null,
            );
        }, 5);
    }

    /**
     * Mengeluarkan stok dan mengembalikan unit_cost average yang dipakai.
     *
     * @param  array{type?: string, id?: int|string|null, line_id?: int|null}|string  $docRef
     */
    public function issue(
        Item|int $item,
        Warehouse|int $warehouse,
        string|int $qtyBase,
        array|string $docRef,
        CarbonInterface|string $date,
        ?int $lineId = null,
    ): string {
        return $this->issueWithValue($item, $warehouse, $qtyBase, $docRef, $date, $lineId)['unit_cost'];
    }

    /**
     * Sama seperti issue(), tetapi mengembalikan nilai baris ledger juga agar
     * dokumen dapat memakai nilai yang benar-benar tercatat.
     *
     * @param  array{type?: string, id?: int|string|null, line_id?: int|null}|string  $docRef
     * @return array{unit_cost: string, total_value: string, ledger: StockLedger}
     */
    public function issueWithValue(
        Item|int $item,
        Warehouse|int $warehouse,
        string|int $qtyBase,
        array|string $docRef,
        CarbonInterface|string $date,
        ?int $lineId = null,
    ): array {
        $itemModel = $this->item($item);
        $warehouseModel = $this->warehouse($warehouse);
        $qty = $this->positiveQty($qtyBase);
        $ref = $this->docRef($docRef, $lineId);
        $day = $this->day($date);

        return DB::transaction(function () use ($itemModel, $warehouseModel, $qty, $ref, $day): array {
            $this->periods->assertOpen($day, true);
            $balance = $this->lockBalance((int) $itemModel->getKey(), (int) $warehouseModel->getKey());

            $oldQty = $this->qty((string) $balance->qty_base);
            $oldValue = $this->money((string) $balance->value);
            $oldAvg = $this->qty((string) $balance->avg_cost);
            $allowNegative = $this->settings->bool('allow_negative_stock', false);

            if (! $allowNegative && Decimal::compare($qty, $oldQty, Decimal::QTY_SCALE) > 0) {
                throw $this->insufficient($itemModel, $warehouseModel, $oldQty, $qty);
            }

            $total = Decimal::compare($qty, $oldQty, Decimal::QTY_SCALE) === 0
                ? $oldValue
                : Decimal::round(
                    Decimal::mul($qty, $oldAvg, Decimal::QTY_SCALE + 2),
                    Decimal::MONEY_SCALE,
                );

            // Koreksi pembulatan: pengeluaran parsial tidak boleh membuat nilai
            // persediaan menjadi negatif.
            if (Decimal::compare($qty, $oldQty, Decimal::QTY_SCALE) < 0
                && Decimal::compare($total, $oldValue, Decimal::MONEY_SCALE) > 0) {
                $total = $oldValue;
            }

            $ledger = $this->persistMovement(
                $balance,
                (int) $itemModel->getKey(),
                (int) $warehouseModel->getKey(),
                $day,
                '0.0000',
                $qty,
                $oldAvg,
                $total,
                $ref,
                null,
            );

            return [
                'unit_cost' => $oldAvg,
                'total_value' => $total,
                'ledger' => $ledger,
            ];
        }, 5);
    }

    /**
     * Mengunci seluruh pasangan saldo dalam urutan deterministik.
     *
     * Dokumen multi-baris wajib memanggil ini sebelum mutasi agar urutan lock
     * sama untuk semua transaksi.
     *
     * @param  list<array{item_id?: int, warehouse_id?: int}|array{0: int, 1: int}>  $pairs
     */
    public function lockBalances(array $pairs): void
    {
        $normalized = [];

        foreach ($pairs as $pair) {
            $itemId = (int) ($pair['item_id'] ?? $pair[0] ?? 0);
            $warehouseId = (int) ($pair['warehouse_id'] ?? $pair[1] ?? 0);

            if ($itemId <= 0 || $warehouseId <= 0) {
                continue;
            }

            $normalized["{$itemId}:{$warehouseId}"] = [$itemId, $warehouseId];
        }

        $normalized = array_values($normalized);

        usort(
            $normalized,
            static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1],
        );

        foreach ($normalized as [$itemId, $warehouseId]) {
            $this->lockBalance($itemId, $warehouseId);
        }
    }

    /**
     * Membalik seluruh mutasi aktif dokumen dengan baris pembalik baru.
     *
     * Aturan SPEC 3.3(c): periode dokumen masih open, tidak ada mutasi
     * lanjutan pada item dan gudang yang sama, dan pembalikan tidak membuat
     * stok negatif. Baris yang sudah pernah dibalik tidak ikut dibalik lagi,
     * sehingga approve -> disapprove -> approve -> disapprove tetap aman.
     *
     * @param  array{type?: string, id?: int|string|null, line_id?: int|null}|string  $docRef
     * @return list<StockLedger>
     */
    public function reverse(array|string $docRef, CarbonInterface|string|null $date = null): array
    {
        $ref = $this->docRef($docRef);

        if ($ref['id'] === null) {
            throw ApiException::unprocessable(
                'Pembalikan stok membutuhkan doc_id dokumen.',
                'STOCK_DOC_REF_INVALID',
            );
        }

        return DB::transaction(function () use ($ref, $date): array {
            $initial = $this->activeRows($ref);

            if ($initial->isEmpty()) {
                $exists = StockLedger::query()
                    ->where('doc_type', $ref['type'])
                    ->where('doc_id', $ref['id'])
                    ->exists();

                if (! $exists) {
                    throw ApiException::notFound(
                        'Mutasi stok untuk dokumen tersebut tidak ditemukan.',
                        'STOCK_NOT_FOUND',
                    );
                }

                throw ApiException::conflict(
                    'Seluruh mutasi stok dokumen ini sudah dibalik.',
                    'STOCK_ALREADY_REVERSED',
                );
            }

            $pairs = $initial
                ->map(static fn (StockLedger $row): array => [
                    'item_id' => (int) $row->item_id,
                    'warehouse_id' => (int) $row->warehouse_id,
                ])
                ->all();

            $day = $date === null
                ? $this->day((string) $initial->max('movement_date'))
                : $this->day($date);

            // Urutan lock: periode lebih dulu, lalu saldo item/warehouse.
            $this->periods->assertOpen($day, true);
            $this->lockBalances($pairs);

            $rows = $this->activeRows($ref);

            if ($rows->isEmpty()) {
                throw ApiException::conflict(
                    'Seluruh mutasi stok dokumen ini sudah dibalik.',
                    'STOCK_ALREADY_REVERSED',
                );
            }

            $this->assertNoLaterMovements($rows);

            $allowNegative = $this->settings->bool('allow_negative_stock', false);
            $reversals = [];

            foreach ($rows as $row) {
                $itemModel = $this->item((int) $row->item_id);
                $warehouseModel = $this->warehouse((int) $row->warehouse_id);
                $balance = $this->lockBalance((int) $row->item_id, (int) $row->warehouse_id);

                $originalQty = $this->qty((string) (
                    Decimal::compare((string) $row->qty_in_base, '0', Decimal::QTY_SCALE) > 0
                        ? $row->qty_in_base
                        : $row->qty_out_base
                ));
                $unitCost = $this->qty((string) $row->unit_cost);
                $totalValue = $this->money((string) $row->total_value);
                $lineRef = [
                    'type' => $ref['type'],
                    'id' => $ref['id'],
                    'line_id' => $row->doc_line_id === null ? null : (int) $row->doc_line_id,
                ];

                if (Decimal::compare((string) $row->qty_in_base, '0', Decimal::QTY_SCALE) > 0) {
                    $available = $this->qty((string) $balance->qty_base);

                    if (! $allowNegative && Decimal::compare($originalQty, $available, Decimal::QTY_SCALE) > 0) {
                        throw $this->insufficient($itemModel, $warehouseModel, $available, $originalQty);
                    }

                    $reversals[] = $this->persistMovement(
                        $balance,
                        (int) $row->item_id,
                        (int) $row->warehouse_id,
                        $day,
                        '0.0000',
                        $originalQty,
                        $unitCost,
                        $totalValue,
                        $lineRef,
                        (int) $row->getKey(),
                    );

                    continue;
                }

                $reversals[] = $this->persistMovement(
                    $balance,
                    (int) $row->item_id,
                    (int) $row->warehouse_id,
                    $day,
                    $originalQty,
                    '0.0000',
                    $unitCost,
                    $totalValue,
                    $lineRef,
                    (int) $row->getKey(),
                );
            }

            return $reversals;
        }, 5);
    }

    /**
     * Apakah dokumen masih memiliki mutasi yang belum dibalik.
     *
     * @param  array{type?: string, id?: int|string|null, line_id?: int|null}|string  $docRef
     */
    public function hasActiveMovements(array|string $docRef): bool
    {
        $ref = $this->docRef($docRef);

        if ($ref['id'] === null) {
            return false;
        }

        return $this->activeRows($ref)->isNotEmpty();
    }

    /**
     * Saldo saat ini; mengembalikan saldo nol (belum tersimpan) bila belum ada.
     */
    public function balance(Item|int $item, Warehouse|int $warehouse): StockBalance
    {
        $itemId = $item instanceof Item ? (int) $item->getKey() : (int) $item;
        $warehouseId = $warehouse instanceof Warehouse ? (int) $warehouse->getKey() : (int) $warehouse;

        /** @var StockBalance|null $balance */
        $balance = StockBalance::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        if ($balance instanceof StockBalance) {
            return $balance;
        }

        return new StockBalance([
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'qty_base' => '0.0000',
            'avg_cost' => '0.0000',
            'value' => '0.00',
        ]);
    }

    public function available(Item|int $item, Warehouse|int $warehouse): string
    {
        return $this->qty((string) $this->balance($item, $warehouse)->qty_base);
    }

    /**
     * @param  array{type: string, id: int|null, line_id: int|null}  $ref
     * @return Collection<int, StockLedger>
     */
    private function activeRows(array $ref): Collection
    {
        return StockLedger::query()
            ->where('doc_type', $ref['type'])
            ->where('doc_id', $ref['id'])
            ->whereNull('reversal_of_id')
            ->whereNotIn(
                'id',
                StockLedger::query()
                    ->select('reversal_of_id')
                    ->whereNotNull('reversal_of_id'),
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, StockLedger>  $rows
     */
    private function assertNoLaterMovements(Collection $rows): void
    {
        $maxIds = [];

        foreach ($rows as $row) {
            $key = $row->item_id.':'.$row->warehouse_id;
            $maxIds[$key] = max($maxIds[$key] ?? 0, (int) $row->getKey());
        }

        foreach ($maxIds as $key => $maxId) {
            [$itemId, $warehouseId] = array_map('intval', explode(':', (string) $key));

            $later = StockLedger::query()
                ->where('item_id', $itemId)
                ->where('warehouse_id', $warehouseId)
                ->where('id', '>', $maxId)
                ->orderBy('id')
                ->first();

            if (! $later instanceof StockLedger) {
                continue;
            }

            $itemModel = $this->item($itemId);
            $warehouseModel = $this->warehouse($warehouseId);

            throw ApiException::conflict(
                "Pembalikan stok {$itemModel->name} di {$warehouseModel->name} ditolak: "
                ."ada mutasi lanjutan pada {$later->movement_date?->toDateString()} (ledger #{$later->getKey()}).",
                'STOCK_REVERSAL_BLOCKED',
                ['references' => ["stock_ledger:{$later->getKey()}"]],
            );
        }
    }

    private function lockBalance(int $itemId, int $warehouseId): StockBalance
    {
        $now = now();

        StockBalance::query()->insertOrIgnore([
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'qty_base' => '0.0000',
            'avg_cost' => '0.0000',
            'value' => '0.00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var StockBalance $balance */
        $balance = StockBalance::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->firstOrFail();

        return $balance;
    }

    /**
     * @param  array{type: string, id: int|null, line_id: int|null}  $ref
     */
    private function persistMovement(
        StockBalance $balance,
        int $itemId,
        int $warehouseId,
        CarbonImmutable $day,
        string $qtyIn,
        string $qtyOut,
        string $unitCost,
        string $totalValue,
        array $ref,
        ?int $reversalOfId,
    ): StockLedger {
        $oldQty = $this->qty((string) $balance->qty_base);
        $oldValue = $this->money((string) $balance->value);
        $oldAvg = $this->qty((string) $balance->avg_cost);

        $newQty = Decimal::add($oldQty, $qtyIn, Decimal::QTY_SCALE);
        $newQty = Decimal::sub($newQty, $qtyOut, Decimal::QTY_SCALE);

        $signedValue = Decimal::compare($qtyIn, '0', Decimal::QTY_SCALE) > 0
            ? $totalValue
            : Decimal::mul($totalValue, '-1', Decimal::MONEY_SCALE);
        $newValue = Decimal::add($oldValue, $signedValue, Decimal::MONEY_SCALE);

        $newAvg = $oldAvg;

        if (Decimal::compare($newQty, '0', Decimal::QTY_SCALE) > 0) {
            $newAvg = Decimal::div($newValue, $newQty, Decimal::QTY_SCALE);
        } elseif (Decimal::compare($newQty, '0', Decimal::QTY_SCALE) === 0) {
            $newAvg = '0.0000';
        }

        /** @var StockLedger $ledger */
        $ledger = StockLedger::query()->create([
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'movement_date' => $day->toDateString(),
            'doc_type' => $ref['type'],
            'doc_id' => $ref['id'],
            'doc_line_id' => $ref['line_id'],
            'qty_in_base' => $qtyIn,
            'qty_out_base' => $qtyOut,
            'unit_cost' => $unitCost,
            'total_value' => $totalValue,
            'balance_qty' => $newQty,
            'balance_value' => $newValue,
            'avg_cost_after' => $newAvg,
            'reversal_of_id' => $reversalOfId,
            'created_by' => $this->actorId(),
        ]);

        $balance->forceFill([
            'qty_base' => $newQty,
            'avg_cost' => $newAvg,
            'value' => $newValue,
        ])->save();

        return $ledger;
    }

    /**
     * @param  array{type?: string, id?: int|string|null, line_id?: int|null}|string  $docRef
     * @return array{type: string, id: int|null, line_id: int|null}
     */
    private function docRef(array|string $docRef, ?int $lineId = null): array
    {
        if (is_string($docRef)) {
            $type = strtoupper(trim($docRef));

            return [
                'type' => $type === '' ? 'ADJ' : $type,
                'id' => null,
                'line_id' => $lineId,
            ];
        }

        $type = strtoupper(trim((string) ($docRef['type'] ?? 'ADJ')));

        return [
            'type' => $type === '' ? 'ADJ' : $type,
            'id' => isset($docRef['id']) ? (int) $docRef['id'] : null,
            'line_id' => isset($docRef['line_id'])
                ? (int) $docRef['line_id']
                : $lineId,
        ];
    }

    private function item(Item|int $item): Item
    {
        return $item instanceof Item
            ? $item
            : Item::query()->findOrFail($item);
    }

    private function warehouse(Warehouse|int $warehouse): Warehouse
    {
        return $warehouse instanceof Warehouse
            ? $warehouse
            : Warehouse::query()->findOrFail($warehouse);
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

    private function positiveQty(string|int $value): string
    {
        $qty = $this->qty($value);

        if (Decimal::compare($qty, '0', Decimal::QTY_SCALE) <= 0) {
            throw ApiException::unprocessable(
                'Qty stok harus lebih besar dari nol.',
                'STOCK_QTY_INVALID',
                ['qty_base' => ['Qty harus lebih besar dari nol.']],
            );
        }

        return $qty;
    }

    private function nonNegativeCost(string|int $value): string
    {
        $cost = $this->qty($value);

        if (Decimal::isNegative($cost)) {
            throw ApiException::unprocessable(
                'Biaya satuan tidak boleh negatif.',
                'STOCK_COST_INVALID',
                ['unit_cost' => ['Biaya satuan tidak boleh negatif.']],
            );
        }

        return $cost;
    }

    private function nonNegativeMoney(string|int $value): string
    {
        $money = $this->money($value);

        if (Decimal::isNegative($money)) {
            throw ApiException::unprocessable(
                'Nilai persediaan tidak boleh negatif.',
                'STOCK_COST_INVALID',
                ['total_value' => ['Nilai persediaan tidak boleh negatif.']],
            );
        }

        return $money;
    }

    private function insufficient(
        Item $item,
        Warehouse $warehouse,
        string $available,
        string $requested,
    ): ApiException {
        return ApiException::unprocessable(
            "Stok {$item->name} ({$item->sku}) di {$warehouse->name} tidak cukup: "
            ."tersedia {$available}, diminta {$requested}.",
            'STOCK_INSUFFICIENT',
            ['qty_base' => ["Tersedia {$available}, diminta {$requested}."]],
        );
    }

    private function actorId(): ?int
    {
        $user = app()->bound('request') ? request()->user() : null;
        $id = $user?->getAuthIdentifier() ?? Auth::id();

        return $id === null ? null : (int) $id;
    }
}
