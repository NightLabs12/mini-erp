<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Master\Models\Item;
use App\Http\Controllers\Controller;
use App\Support\Math\Decimal;
use Illuminate\Http\JsonResponse;

/**
 * Saldo stok satu item di seluruh gudang, plus total qty dan nilai.
 */
final class ItemStockController extends Controller
{
    public function show(Item $item): JsonResponse
    {
        $this->authorize('view', $item);

        /** @var \Illuminate\Support\Collection<int, StockBalance> $balances */
        $balances = StockBalance::query()
            ->with('warehouse')
            ->where('item_id', $item->getKey())
            ->orderBy('warehouse_id')
            ->get();

        $totalQty = '0.0000';
        $totalValue = '0.00';

        $rows = $balances->map(static function (StockBalance $balance) use (&$totalQty, &$totalValue): array {
            $totalQty = Decimal::add($totalQty, (string) $balance->qty_base, Decimal::QTY_SCALE);
            $totalValue = Decimal::add($totalValue, (string) $balance->value, Decimal::MONEY_SCALE);

            return [
                'warehouse_id' => $balance->warehouse_id,
                'warehouse' => $balance->warehouse === null ? null : [
                    'id' => $balance->warehouse->getKey(),
                    'code' => $balance->warehouse->code,
                    'name' => $balance->warehouse->name,
                ],
                'qty_base' => $balance->qty_base,
                'avg_cost' => $balance->avg_cost,
                'value' => $balance->value,
            ];
        })->all();

        return response()->json([
            'data' => [
                'item' => [
                    'id' => $item->getKey(),
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'base_unit_id' => $item->base_unit_id,
                ],
                'total_qty_base' => $totalQty,
                'total_value' => $totalValue,
                'balances' => $rows,
            ],
        ]);
    }
}
