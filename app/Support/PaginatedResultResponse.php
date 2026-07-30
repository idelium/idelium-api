<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PaginatedResultResponse
{
    public function build(
        Request $request,
        Builder $query,
        array $sortableColumns,
        string $defaultSort,
        string $defaultDirection,
        ?callable $transform = null
    ) {
        if ($request->filled('status')) {
            $query->where('status', (int) $request->query('status'));
        }

        $sort = $request->query('sort', $defaultSort);
        if (! in_array($sort, $sortableColumns, true)) {
            $sort = $defaultSort;
        }

        $direction = strtolower((string) $request->query('direction', $defaultDirection));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = $defaultDirection;
        }

        $query->orderBy($sort, $direction);

        if (! $request->filled('page') && ! $request->filled('perPage')) {
            $results = $query->get();

            return $transform === null ? $results : $results->map($transform);
        }

        $perPage = min(max((int) $request->query('perPage', 25), 1), 100);
        $page = max((int) $request->query('page', 1), 1);
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items());
        if ($transform !== null) {
            $items = $items->map($transform);
        }

        return response()->json([
            'data' => $items->values(),
            'meta' => [
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'perPage' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'lastPage' => $paginator->lastPage(),
                    'sort' => $sort,
                    'direction' => $direction,
                ],
            ],
        ]);
    }
}
