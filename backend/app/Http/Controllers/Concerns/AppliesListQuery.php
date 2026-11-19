<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Support\Api\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait AppliesListQuery
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array{sortable?: list<string>, filterable?: list<string>, searchable?: list<string>, default_sort?: string, per_page?: int, max_per_page?: int}  $config
     * @return Builder<TModel>
     */
    protected function applyListQuery(Builder $query, Request $request, array $config = []): Builder
    {
        return ListQuery::fromConfig($config)->apply($query, $request);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array{sortable?: list<string>, filterable?: list<string>, searchable?: list<string>, default_sort?: string, per_page?: int, max_per_page?: int}  $config
     * @return LengthAwarePaginator<TModel>
     */
    protected function paginateListQuery(Builder $query, Request $request, array $config = []): LengthAwarePaginator
    {
        $listQuery = ListQuery::fromConfig($config);

        return $listQuery->paginate($listQuery->apply($query, $request), $request);
    }
}
