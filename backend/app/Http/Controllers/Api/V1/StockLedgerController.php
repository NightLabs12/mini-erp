<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Models\StockLedger;
use App\Http\Controllers\Concerns\AppliesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\StockLedgerResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Daftar mutasi ledger stok. Ledger append-only dan tidak memiliki endpoint
 * tulis; mutasi hanya lahir dari dokumen lewat StockService.
 *
 * Hak akses dijaga middleware permission:inventory.stock.view di route.
 */
final class StockLedgerController extends Controller
{
    use AppliesListQuery;

    public function index(Request $request): ApiCollection
    {
        $query = StockLedger::query()->with(['item.baseUnit', 'warehouse']);

        $this->applySearch($query, $request);

        $paginator = $this->paginateListQuery($query, $request, [
            'sortable' => ['id', 'movement_date', 'doc_type', 'created_at'],
            'filterable' => [
                'item_id',
                'warehouse_id',
                'doc_type',
                'doc_id',
                'doc_line_id',
                'movement_date',
                'reversal_of_id',
                'created_at',
            ],
            'default_sort' => '-id',
            'per_page' => 25,
        ]);

        return new ApiCollection($paginator, StockLedgerResource::class);
    }

    /**
     * @param  Builder<StockLedger>  $query
     */
    private function applySearch(Builder $query, Request $request): void
    {
        $term = trim((string) $request->query('search', ''));

        if ($term === '') {
            return;
        }

        $pattern = '%'.$this->escapeLike($term).'%';

        $query->where(function (Builder $nested) use ($pattern): void {
            $nested->where('doc_type', 'ilike', $pattern)
                ->orWhereHas('item', static function (Builder $item) use ($pattern): void {
                    $item->where('sku', 'ilike', $pattern)
                        ->orWhere('name', 'ilike', $pattern);
                });
        });
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
