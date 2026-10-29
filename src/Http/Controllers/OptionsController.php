<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fastleo;
use Fastleo\Fields\Contracts\SearchesOptions;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Fastleo\Support\RecordKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Варианты поля-списка с поиском по серверу (связи с большим числом записей).
 */
final class OptionsController
{
    /** Сколько вариантов отдаётся за раз. */
    private const int LIMIT = 20;

    /**
     * `GET /fastleo/{resource}/options/{field}?q=текст&depends=значение` → `{"options": [{"value": "1", "label": "…"}]}`.
     * `depends` — значение поля, от которого зависит список (`dependsOn()`); `record` — ключ записи формы.
     * Если админ не может менять поле-зависимость, `depends` не учитывается: берётся значение записи
     * (нужно право её изменять), при создании — значение по умолчанию. Иначе подделкой `depends`
     * можно было бы увидеть чужие варианты.
     * Нужны право просмотра раздела и поле, которое можно менять в форме.
     */
    public function __invoke(Request $request, string $resource, string $field): JsonResponse
    {
        $resource = Fastleo::resources()->find($resource);
        abort_unless($resource instanceof Resource, 404);
        Authorization::authorize('viewAny', $resource);

        $field = $resource->schema()->field($field);
        abort_unless($field instanceof SearchesOptions && $field->isFormField() && $field->isWritable(), 404);

        $search = $request->query('q');
        $search = is_string($search) ? mb_substr(trim($search), 0, 255) : '';
        $depends = $field->getDependsOn();
        $dependency = null;

        if ($depends !== null) {
            $key = $request->query('record');
            $record = null;

            if (is_string($key) && $key !== '') {
                abort_unless(RecordKey::valid($resource->newModel(), $key), 404);
                $record = $resource->newQuery()->whereKey($key)->first();
                abort_unless($record instanceof Model, 404);
                Authorization::authorize('update', $resource, $record);
            }

            $value = $request->query('depends');
            $dependency = $resource->controllingValue($depends, $record, [$depends => is_string($value) ? mb_substr($value, 0, 255) : null]);
        }

        try {
            $options = $field->searchOptions($search, self::LIMIT, $dependency);
        } catch (QueryException) {
            // Значение зависимости не того типа, что колонка (строка для числа на PostgreSQL): вариантов нет.
            $options = [];
        }

        return response()->json(['options' => $options]);
    }
}
