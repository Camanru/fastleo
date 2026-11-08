<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Exports\Exporter;
use Fastleo\Exports\SpreadsheetFormats;
use Fastleo\Fastleo;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Fastleo\Support\ResourceQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Выгрузка раздела в CSV (xlsx отключён, `SpreadsheetFormats`) — то, что сейчас в списке (поиск, фильтры, сортировка, корзина), на всех страницах.
 */
final class ExportController
{
    /**
     * `GET /fastleo/{resource}/export?format=csv` + параметры списка (формат не из `SpreadsheetFormats::all()` — формат по умолчанию).
     * Право — метод `export` Policy, если он есть, иначе `viewAny`.
     */
    public function __invoke(Request $request, string $resource): StreamedResponse
    {
        $resource = Fastleo::resources()->find($resource);
        abort_unless($resource instanceof Resource && $resource::isExportable(), 404);
        abort_unless(Authorization::allowsOwnOr('export', 'viewAny', $resource), 403);

        $format = $request->query('format');
        $format = is_string($format) && in_array($format, SpreadsheetFormats::all(), true) ? $format : SpreadsheetFormats::preferred();
        $trashed = $resource::usesSoftDeletes() && $request->query('trashed') === '1';

        return new Exporter($resource)->download(new ResourceQuery($resource, $request, $trashed)->query(), $format);
    }
}
