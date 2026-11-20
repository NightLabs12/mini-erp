<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Warehouse;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Laporan persediaan: Kartu Stok, Posisi Stok, dan Mutasi Stok.
 *
 * Saldo berjalan dihitung dari mutasi bertanggal, bukan dari kolom balance
 * ledger, sehingga dokumen bertanggal mundur tetap tampil konsisten. Semua
 * parameter masuk sebagai binding.
 */
final class StockReportService
{
    /**
     * @return array<string, mixed>
     */
    public function stockCard(int $itemId, ?int $warehouseId, string $from, string $to): array
    {
        /** @var Item $item */
        $item = Item::query()->with('baseUnit')->findOrFail($itemId);

        $opening = DB::select(
            <<<'SQL'
                SELECT warehouse_id,
                       COALESCE(SUM(qty_in_base - qty_out_base), 0) AS qty,
                       COALESCE(SUM(CASE WHEN qty_in_base > 0 THEN total_value ELSE -total_value END), 0) AS value
                  FROM stock_ledger
                 WHERE item_id = ?
                   AND movement_date < ?
                   AND (CAST(? AS bigint) IS NULL OR warehouse_id = CAST(? AS bigint))
                 GROUP BY warehouse_id
            SQL,
            [$itemId, $from, $warehouseId, $warehouseId],
        );

        $lines = DB::select(
            <<<'SQL'
                SELECT l.id,
                       l.warehouse_id,
                       l.movement_date,
                       l.doc_type,
                       l.doc_id,
                       l.doc_line_id,
                       l.qty_in_base,
                       l.qty_out_base,
                       l.unit_cost,
                       l.total_value,
                       l.reversal_of_id,
                       l.created_at,
                       SUM(l.qty_in_base - l.qty_out_base) OVER (
                           PARTITION BY l.warehouse_id
                           ORDER BY l.movement_date, l.id
                           ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                       ) AS period_qty,
                       SUM(CASE WHEN l.qty_in_base > 0 THEN l.total_value ELSE -l.total_value END) OVER (
                           PARTITION BY l.warehouse_id
                           ORDER BY l.movement_date, l.id
                           ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                       ) AS period_value
                  FROM stock_ledger l
                 WHERE l.item_id = ?
                   AND l.movement_date BETWEEN ? AND ?
                   AND (CAST(? AS bigint) IS NULL OR l.warehouse_id = CAST(? AS bigint))
                 ORDER BY l.warehouse_id, l.movement_date, l.id
            SQL,
            [$itemId, $from, $to, $warehouseId, $warehouseId],
        );

        $openingByWarehouse = [];

        foreach ($opening as $row) {
            $openingByWarehouse[(int) $row->warehouse_id] = [
                'qty' => $this->qty((string) $row->qty),
                'value' => $this->money((string) $row->value),
            ];
        }

        $linesByWarehouse = [];

        foreach ($lines as $row) {
            $linesByWarehouse[(int) $row->warehouse_id][] = $row;
        }

        $warehouseIds = array_values(array_unique([
            ...array_keys($openingByWarehouse),
            ...array_keys($linesByWarehouse),
        ]));
        sort($warehouseIds);

        /** @var \Illuminate\Support\Collection<int, Warehouse> $warehouses */
        $warehouses = Warehouse::query()
            ->whereKey($warehouseIds)
            ->get()
            ->keyBy(static fn (Warehouse $warehouse): int => (int) $warehouse->getKey());

        $result = [];

        foreach ($warehouseIds as $id) {
            $open = $openingByWarehouse[$id] ?? ['qty' => '0.0000', 'value' => '0.00'];
            $runningQty = $open['qty'];
            $runningValue = $open['value'];
            $lineData = [];

            foreach ($linesByWarehouse[$id] ?? [] as $row) {
                $runningQty = $this->qty(Decimal::add($open['qty'], (string) $row->period_qty, Decimal::QTY_SCALE));
                $runningValue = $this->money(Decimal::add($open['value'], (string) $row->period_value, Decimal::MONEY_SCALE));
                $totalValue = $this->money((string) $row->total_value);

                $lineData[] = [
                    'id' => (int) $row->id,
                    'movement_date' => (string) $row->movement_date,
                    'doc_type' => (string) $row->doc_type,
                    'doc_id' => $row->doc_id === null ? null : (int) $row->doc_id,
                    'doc_line_id' => $row->doc_line_id === null ? null : (int) $row->doc_line_id,
                    'qty_in_base' => $this->qty((string) $row->qty_in_base),
                    'qty_out_base' => $this->qty((string) $row->qty_out_base),
                    'unit_cost' => $this->qty((string) $row->unit_cost),
                    'total_value' => $totalValue,
                    'signed_value' => Decimal::compare((string) $row->qty_in_base, '0', Decimal::QTY_SCALE) > 0
                        ? $totalValue
                        : Decimal::mul($totalValue, '-1', Decimal::MONEY_SCALE),
                    'running_qty' => $runningQty,
                    'running_value' => $runningValue,
                    'running_avg_cost' => $this->average($runningQty, $runningValue),
                    'reversal_of_id' => $row->reversal_of_id === null ? null : (int) $row->reversal_of_id,
                    'created_at' => (string) $row->created_at,
                ];
            }

            $warehouse = $warehouses->get($id);

            $result[] = [
                'warehouse' => $warehouse === null ? null : [
                    'id' => (int) $warehouse->getKey(),
                    'code' => $warehouse->code,
                    'name' => $warehouse->name,
                ],
                'opening_qty' => $open['qty'],
                'opening_value' => $open['value'],
                'opening_avg_cost' => $this->average($open['qty'], $open['value']),
                'lines' => $lineData,
                'closing_qty' => $runningQty,
                'closing_value' => $runningValue,
                'closing_avg_cost' => $this->average($runningQty, $runningValue),
            ];
        }

        return [
            'item' => [
                'id' => (int) $item->getKey(),
                'sku' => $item->sku,
                'name' => $item->name,
                'base_unit' => $item->baseUnit === null ? null : [
                    'id' => (int) $item->baseUnit->getKey(),
                    'code' => $item->baseUnit->code,
                    'name' => $item->baseUnit->name,
                ],
            ],
            'from' => $from,
            'to' => $to,
            'warehouses' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stockPosition(?int $itemId, ?int $warehouseId, string $from, string $to): array
    {
        $rows = DB::select(
            <<<'SQL'
                SELECT l.item_id,
                       l.warehouse_id,
                       i.sku,
                       i.name AS item_name,
                       i.base_unit_id,
                       u.code AS base_unit_code,
                       w.code AS warehouse_code,
                       w.name AS warehouse_name,
                       COALESCE(SUM(CASE WHEN l.movement_date < ? THEN l.qty_in_base - l.qty_out_base ELSE 0 END), 0) AS opening_qty,
                       COALESCE(SUM(CASE WHEN l.movement_date < ? THEN CASE WHEN l.qty_in_base > 0 THEN l.total_value ELSE -l.total_value END ELSE 0 END), 0) AS opening_value,
                       COALESCE(SUM(CASE WHEN l.movement_date BETWEEN ? AND ? THEN l.qty_in_base ELSE 0 END), 0) AS in_qty,
                       COALESCE(SUM(CASE WHEN l.movement_date BETWEEN ? AND ? AND l.qty_in_base > 0 THEN l.total_value ELSE 0 END), 0) AS in_value,
                       COALESCE(SUM(CASE WHEN l.movement_date BETWEEN ? AND ? THEN l.qty_out_base ELSE 0 END), 0) AS out_qty,
                       COALESCE(SUM(CASE WHEN l.movement_date BETWEEN ? AND ? AND l.qty_out_base > 0 THEN l.total_value ELSE 0 END), 0) AS out_value
                  FROM stock_ledger l
                  JOIN items i ON i.id = l.item_id
                  JOIN units u ON u.id = i.base_unit_id
                  JOIN warehouses w ON w.id = l.warehouse_id
                 WHERE (CAST(? AS bigint) IS NULL OR l.item_id = CAST(? AS bigint))
                   AND (CAST(? AS bigint) IS NULL OR l.warehouse_id = CAST(? AS bigint))
                 GROUP BY l.item_id, l.warehouse_id, i.sku, i.name, i.base_unit_id, u.code, w.code, w.name
                 ORDER BY i.sku, w.code
            SQL,
            [
                $from,
                $from,
                $from,
                $to,
                $from,
                $to,
                $from,
                $to,
                $from,
                $to,
                $itemId,
                $itemId,
                $warehouseId,
                $warehouseId,
            ],
        );

        $items = [];

        foreach ($rows as $row) {
            $openingQty = $this->qty((string) $row->opening_qty);
            $openingValue = $this->money((string) $row->opening_value);
            $inQty = $this->qty((string) $row->in_qty);
            $inValue = $this->money((string) $row->in_value);
            $outQty = $this->qty((string) $row->out_qty);
            $outValue = $this->money((string) $row->out_value);
            $closingQty = Decimal::sub(
                Decimal::add($openingQty, $inQty, Decimal::QTY_SCALE),
                $outQty,
                Decimal::QTY_SCALE,
            );
            $closingValue = Decimal::sub(
                Decimal::add($openingValue, $inValue, Decimal::MONEY_SCALE),
                $outValue,
                Decimal::MONEY_SCALE,
            );

            $items[] = [
                'item' => [
                    'id' => (int) $row->item_id,
                    'sku' => (string) $row->sku,
                    'name' => (string) $row->item_name,
                    'base_unit_id' => (int) $row->base_unit_id,
                    'base_unit_code' => (string) $row->base_unit_code,
                ],
                'warehouse' => [
                    'id' => (int) $row->warehouse_id,
                    'code' => (string) $row->warehouse_code,
                    'name' => (string) $row->warehouse_name,
                ],
                'opening_qty' => $openingQty,
                'opening_value' => $openingValue,
                'in_qty' => $inQty,
                'in_value' => $inValue,
                'out_qty' => $outQty,
                'out_value' => $outValue,
                'closing_qty' => $closingQty,
                'closing_value' => $closingValue,
                'avg_cost' => $this->average($closingQty, $closingValue),
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stockMovement(?int $itemId, ?int $warehouseId, string $from, string $to): array
    {
        $rows = DB::select(
            <<<'SQL'
                SELECT l.id,
                       l.item_id,
                       l.warehouse_id,
                       l.movement_date,
                       l.doc_type,
                       l.doc_id,
                       l.doc_line_id,
                       l.qty_in_base,
                       l.qty_out_base,
                       l.unit_cost,
                       l.total_value,
                       l.balance_qty,
                       l.balance_value,
                       l.avg_cost_after,
                       l.reversal_of_id,
                       l.created_at,
                       i.sku,
                       i.name AS item_name,
                       w.code AS warehouse_code,
                       w.name AS warehouse_name
                  FROM stock_ledger l
                  JOIN items i ON i.id = l.item_id
                  JOIN warehouses w ON w.id = l.warehouse_id
                 WHERE l.movement_date BETWEEN ? AND ?
                   AND (CAST(? AS bigint) IS NULL OR l.item_id = CAST(? AS bigint))
                   AND (CAST(? AS bigint) IS NULL OR l.warehouse_id = CAST(? AS bigint))
                 ORDER BY l.movement_date, l.id
            SQL,
            [$from, $to, $itemId, $itemId, $warehouseId, $warehouseId],
        );

        $lines = [];
        $totalInQty = '0.0000';
        $totalOutQty = '0.0000';
        $totalInValue = '0.00';
        $totalOutValue = '0.00';

        foreach ($rows as $row) {
            $qtyIn = $this->qty((string) $row->qty_in_base);
            $qtyOut = $this->qty((string) $row->qty_out_base);
            $totalValue = $this->money((string) $row->total_value);
            $isInbound = Decimal::compare($qtyIn, '0', Decimal::QTY_SCALE) > 0;

            $totalInQty = Decimal::add($totalInQty, $qtyIn, Decimal::QTY_SCALE);
            $totalOutQty = Decimal::add($totalOutQty, $qtyOut, Decimal::QTY_SCALE);

            if ($isInbound) {
                $totalInValue = Decimal::add($totalInValue, $totalValue, Decimal::MONEY_SCALE);
            } else {
                $totalOutValue = Decimal::add($totalOutValue, $totalValue, Decimal::MONEY_SCALE);
            }

            $lines[] = [
                'id' => (int) $row->id,
                'movement_date' => (string) $row->movement_date,
                'item' => [
                    'id' => (int) $row->item_id,
                    'sku' => (string) $row->sku,
                    'name' => (string) $row->item_name,
                ],
                'warehouse' => [
                    'id' => (int) $row->warehouse_id,
                    'code' => (string) $row->warehouse_code,
                    'name' => (string) $row->warehouse_name,
                ],
                'doc_type' => (string) $row->doc_type,
                'doc_id' => $row->doc_id === null ? null : (int) $row->doc_id,
                'doc_line_id' => $row->doc_line_id === null ? null : (int) $row->doc_line_id,
                'qty_in_base' => $qtyIn,
                'qty_out_base' => $qtyOut,
                'unit_cost' => $this->qty((string) $row->unit_cost),
                'total_value' => $totalValue,
                'signed_value' => $isInbound
                    ? $totalValue
                    : Decimal::mul($totalValue, '-1', Decimal::MONEY_SCALE),
                'balance_qty' => $this->qty((string) $row->balance_qty),
                'balance_value' => $this->money((string) $row->balance_value),
                'avg_cost_after' => $this->qty((string) $row->avg_cost_after),
                'reversal_of_id' => $row->reversal_of_id === null ? null : (int) $row->reversal_of_id,
                'created_at' => (string) $row->created_at,
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'lines' => $lines,
            'totals' => [
                'count' => count($lines),
                'qty_in_base' => $totalInQty,
                'qty_out_base' => $totalOutQty,
                'value_in' => $totalInValue,
                'value_out' => $totalOutValue,
                'net_value' => Decimal::sub($totalInValue, $totalOutValue, Decimal::MONEY_SCALE),
            ],
        ];
    }

    private function qty(string|int $value): string
    {
        return Decimal::round($value, Decimal::QTY_SCALE);
    }

    private function money(string|int $value): string
    {
        return Decimal::round($value, Decimal::MONEY_SCALE);
    }

    private function average(string $qty, string $value): string
    {
        if (Decimal::compare($qty, '0', Decimal::QTY_SCALE) <= 0) {
            return '0.0000';
        }

        return Decimal::div($value, $qty, Decimal::QTY_SCALE);
    }
}
