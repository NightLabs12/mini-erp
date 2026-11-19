<?php

declare(strict_types=1);

namespace App\Support\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Menerapkan konvensi list endpoint: ?search=, ?filter[key]=, ?sort=, ?page=, ?per_page=.
 *
 * Hanya kolom yang didaftarkan pemanggil yang boleh dipakai, sehingga input
 * pengguna tidak pernah menjadi bagian dari SQL.
 */
final class ListQuery
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  list<string>  $sortable
     * @param  list<string>  $filterable
     * @param  list<string>  $searchable
     */
    public function __construct(
        private readonly array $sortable = [],
        private readonly array $filterable = [],
        private readonly array $searchable = [],
        private readonly string $defaultSort = '-created_at',
        private readonly int $defaultPerPage = self::DEFAULT_PER_PAGE,
        private readonly int $maxPerPage = self::MAX_PER_PAGE,
    ) {
        foreach ([...$this->sortable, ...$this->filterable, ...$this->searchable] as $column) {
            $this->assertColumn($column);
        }

        $this->assertColumn(ltrim($this->defaultSort, '-+'));
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        return new self(
            sortable: self::stringList($config['sortable'] ?? []),
            filterable: self::stringList($config['filterable'] ?? []),
            searchable: self::stringList($config['searchable'] ?? []),
            defaultSort: (string) ($config['default_sort'] ?? '-created_at'),
            defaultPerPage: (int) ($config['per_page'] ?? self::DEFAULT_PER_PAGE),
            maxPerPage: (int) ($config['max_per_page'] ?? self::MAX_PER_PAGE),
        );
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, Request $request): Builder
    {
        $this->applySearch($query, $request);
        $this->applyFilters($query, $request);
        $this->applySort($query, $request);

        return $query;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<TModel>
     */
    public function paginate(Builder $query, Request $request): LengthAwarePaginator
    {
        return $query->paginate($this->perPage($request))->withQueryString();
    }

    /** @return list<string> */
    public static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applySearch(Builder $query, Request $request): void
    {
        $term = trim((string) $request->query('search', ''));

        if ($term === '' || $this->searchable === []) {
            return;
        }

        $pattern = '%'.self::escapeLike($term).'%';

        $query->where(function (Builder $nested) use ($pattern): void {
            foreach ($this->searchable as $column) {
                $nested->orWhereRaw($column.' ILIKE ?', [$pattern]);
            }
        });
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        $filters = $request->query('filter');

        if (! is_array($filters)) {
            return;
        }

        foreach ($this->filterable as $column) {
            if (! array_key_exists($column, $filters)) {
                continue;
            }

            $value = $filters[$column];

            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                $this->applyFilterOperators($query, $column, $value);

                continue;
            }

            if ($value === 'true' || $value === 'false') {
                $query->where($column, $value === 'true');

                continue;
            }

            $query->where($column, $value);
        }
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $operators
     */
    private function applyFilterOperators(Builder $query, string $column, array $operators): void
    {
        foreach ($operators as $operator => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            match ($operator) {
                'eq' => $query->where($column, $value),
                'gte' => $query->where($column, '>=', $value),
                'lte' => $query->where($column, '<=', $value),
                'gt' => $query->where($column, '>', $value),
                'lt' => $query->where($column, '<', $value),
                'from' => $query->whereDate($column, '>=', (string) $value),
                'to' => $query->whereDate($column, '<=', (string) $value),
                'like' => $query->where($column, 'ilike', '%'.self::escapeLike((string) $value).'%'),
                'in' => $query->whereIn($column, is_array($value) ? array_values($value) : explode(',', (string) $value)),
                default => null,
            };
        }
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applySort(Builder $query, Request $request): void
    {
        $sort = (string) $request->query('sort', '');
        $requested = $sort === '' ? [] : array_map('trim', explode(',', $sort));

        $applied = 0;

        foreach ($requested as $column) {
            $descending = str_starts_with($column, '-');
            $name = ltrim($column, '-+');

            if (! in_array($name, $this->sortable, true)) {
                continue;
            }

            $query->orderBy($name, $descending ? 'desc' : 'asc');
            $applied++;
        }

        if ($applied === 0) {
            $descending = str_starts_with($this->defaultSort, '-');

            $query->orderBy(ltrim($this->defaultSort, '-+'), $descending ? 'desc' : 'asc');
        }
    }

    private function perPage(Request $request): int
    {
        $perPage = filter_var($request->query('per_page'), FILTER_VALIDATE_INT);

        if (! is_int($perPage)) {
            return $this->defaultPerPage;
        }

        return max(1, min($perPage, $this->maxPerPage));
    }

    private function assertColumn(string $column): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/', $column) !== 1) {
            throw new InvalidArgumentException("Nama kolom tidak valid: {$column}");
        }
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
