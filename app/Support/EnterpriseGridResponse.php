<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

class EnterpriseGridResponse
{
    public function build(
        Request $request,
        EloquentBuilder|QueryBuilder $query,
        array $sortableColumns,
        string $defaultSort,
        string $defaultDirection,
        array $searchColumns = [],
        array $filterColumns = [],
    ) {
        $this->applySearch($request, $query, $searchColumns);
        $this->applyFilters($request, $query, $filterColumns);

        $sortMap = array_is_list($sortableColumns)
            ? array_combine($sortableColumns, $sortableColumns)
            : $sortableColumns;
        $sort = (string) $request->query('sort', $defaultSort);
        if (! array_key_exists($sort, $sortMap)) {
            $sort = $defaultSort;
        }

        $direction = strtolower((string) $request->query('direction', $defaultDirection));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = $defaultDirection;
        }

        $query->orderBy($sortMap[$sort], $direction);

        if (! $request->filled('page') && ! $request->filled('pageSize')) {
            return $query->get();
        }

        $pageSize = min(max((int) $request->query('pageSize', 25), 1), 100);
        $page = max((int) $request->query('page', 1), 1);
        $paginator = $query->paginate($pageSize, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'pageSize' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'hasNextPage' => $paginator->hasMorePages(),
                'hasPreviousPage' => $paginator->currentPage() > 1,
                'sort' => $sort,
                'direction' => $direction,
                'stale' => false,
                'partial' => false,
            ],
        ]);
    }

    private function applySearch(
        Request $request,
        EloquentBuilder|QueryBuilder $query,
        array $columns
    ): void {
        $search = trim((string) $request->query('search', ''));
        if ($search === '' || $columns === []) {
            return;
        }

        $boundedSearch = mb_substr($search, 0, 200);
        $query->where(function ($builder) use ($columns, $boundedSearch) {
            foreach ($columns as $column) {
                $builder->orWhere($column, 'like', '%'.$boundedSearch.'%');
            }
        });
    }

    private function applyFilters(
        Request $request,
        EloquentBuilder|QueryBuilder $query,
        array $columns
    ): void {
        $filters = $request->query('filter', []);
        if (! is_array($filters)) {
            return;
        }

        foreach ($filters as $key => $value) {
            if (! in_array($key, $columns, true) || $value === null || $value === '') {
                continue;
            }

            $values = is_array($value) ? $value : explode(',', (string) $value);
            $values = array_values(array_filter(array_map(
                fn ($entry) => trim(mb_substr((string) $entry, 0, 200)),
                $values
            )));

            if ($values !== []) {
                $query->whereIn($key, $values);
            }
        }
    }
}
