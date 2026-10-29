<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fastleo;
use Fastleo\Filters\BelongsToFilter;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Варианты фильтра по связи с поиском по серверу (когда связанных записей много).
 */
final class FilterOptionsController
{
    /** Сколько вариантов отдаётся за раз. */
    private const int LIMIT = 20;

    /**
     * `GET /fastleo/{resource}/filter-options/{filter}?q=текст` → `{"options": [{"value": "1", "label": "…"}]}`.
     * Нужно право просмотра раздела; фильтр — `BelongsToFilter` из `filters()` ресурса.
     */
    public function __invoke(Request $request, string $resource, string $filter): JsonResponse
    {
        $resource = Fastleo::resources()->find($resource);
        abort_unless($resource instanceof Resource, 404);
        Authorization::authorize('viewAny', $resource);

        $filter = $resource->listFilters()[$filter] ?? null;
        abort_unless($filter instanceof BelongsToFilter, 404);

        $search = $request->query('q');
        $search = is_string($search) ? mb_substr(trim($search), 0, 255) : '';

        return response()->json(['options' => $filter->searchOptions($search, self::LIMIT)]);
    }
}
