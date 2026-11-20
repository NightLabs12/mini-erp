<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Models\StockBalance;
use App\Http\Controllers\Concerns\AppliesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\StockBalanceResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Daftar saldo stok per item dan gudang.
 *
 * Hak akses dijaga middleware permission:inventory.stock.view di route.
 */
final class StockBalanceController extends Controller
{
    use AppliesListQuery;

    public function index(Request $request): ApiCollection
    {
        $query = StockBalance::query()->with(['item.baseUnit', 'warehouse']);

        $this->applySearch($query, $request);

        $paginator = $this->paginateListQuery($query, $request, [
            'sortable' => ['item_id', 'warehouse_id', 'qty_base', 'avg_cost', 'value', 'updated_at'],
            'filterable' => ['item_id', 'warehouse_id', 'updated_at'],
            'default_sort' => 'item_id',
            'per_page' => 25,
        ]);

        return new ApiCollection($paginator, StockBalanceResource::class);
    }

    /**
     * @param  Builder<StockBalance>  $query
     */
    private function applySearch(Builder $query, Request $request): void
    {
        $term = trim((string) $request->query('search', ''));

        if ($term === '') {
            return;
        }

        $pattern = '%'.$this->escapeLike($term).'%';

        $query->where(function (Builder $nested) use ($pattern): void {
            $nested->whereHas('item', static function (Builder $item) use ($pattern): void {
                $item->where('sku', 'ilike', $pattern)
                    ->orWhere('name', 'ilike', $pattern);
            })->orWhereHas('warehouse', static function (Builder $warehouse) use ($pattern): void {
                $warehouse->where('code', 'ilike', $pattern)
                    ->orWhere('name', 'ilike', $pattern);
            });
        });
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
