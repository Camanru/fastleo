<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fastleo;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Fastleo\Support\RecordKey;
use Fastleo\Support\ReturnUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Раскрытая строка связанной таблицы: связанные таблицы самой записи (`relations()` её раздела), каждая подгружается
 * отдельным запросом, как на странице записи. Нужно право открыть запись (`view`) — то же, что у страницы просмотра;
 * права на сами вложенные таблицы проверяет `RelatedController`.
 */
final class ExpandController
{
    /**
     * `GET /fastleo/{resource}/{id}/expand` → разметка вложенных таблиц.
     */
    public function __invoke(Request $request, string $resource, string $id): View
    {
        $resource = Fastleo::resources()->find($resource);
        abort_unless($resource instanceof Resource, 404);

        abort_unless(RecordKey::valid($resource->newModel(), $id), 404);
        $record = $resource->newQuery()->whereKey($id)->first();
        abort_unless($record instanceof Model, 404);

        Authorization::authorize('view', $resource, $record);

        return view('fastleo::resources.expand', [
            'resource' => $resource,
            'record' => $record,
            'returnUrl' => ReturnUrl::from($request) ?? route('fastleo.resources.show', [$resource::slug(), $record->getKey()]),
        ]);
    }
}
