<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fastleo;
use Fastleo\Fields\Checkbox;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Fastleo\Support\DatabaseErrors;
use Fastleo\Support\OperationCancelled;
use Fastleo\Support\RecordKey;
use Fastleo\Support\RecordWriter;
use Fastleo\Support\ValidationMessages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Быстрый переключатель в списке: меняет одну галочку записи без открытия формы.
 *
 * Работает только для поля с `quickToggle()`. Проверяется то же, что при сохранении формы:
 * право `update` на запись (в разделе `$readonly` его нет), поле видно и не `readonly()`,
 * правила поля; запись идёт через `RecordWriter` и Eloquent.
 */
final class ToggleController
{
    /**
     * `POST /fastleo/{resource}/{id}/toggle/{field}` с `value` (`1`/`0`) → `{"value": true}`;
     * без JSON — возврат на страницу с сообщением.
     */
    public function __invoke(Request $request, string $resource, string $id, string $field): JsonResponse|RedirectResponse
    {
        $resource = Fastleo::resources()->find($resource);
        abort_unless($resource instanceof Resource, 404);

        $model = $resource->newModel();
        abort_unless(RecordKey::valid($model, $id), 404);
        $record = $resource->newQuery()->whereKey($id)->first();
        abort_unless($record instanceof Model, 404);
        Authorization::authorize('update', $resource, $record);

        $field = $resource->schema()->field($field);
        abort_unless($field instanceof Checkbox && $field->isQuickToggle(), 404);
        abort_unless(in_array($field, $resource->writableFields($record), true) && $field->isConditionMet($record), 403);

        // Без значения «снятая галочка» означала бы false: переключатель всегда присылает явное 1 или 0.
        abort_unless($request->has('value'), 422);

        // Правило условия показа (`visibleWhen`) ищет управляющее поле среди данных: берём его из записи, как это делает форма.
        $data = [$field->name => $request->input('value')];
        $controlling = $field->getVisibleWhen()['field'] ?? null;

        if ($controlling !== null) {
            $data[$controlling] = $resource->controllingValue($controlling, $record, null);
        }

        $validator = validator(
            $data,
            RecordWriter::rules($resource, $record, [$field]),
            ValidationMessages::all(),
            $resource->validationAttributes($record),
        );

        if ($validator->fails()) {
            return $this->failed($request, $resource, $validator->errors()->first(), 422);
        }

        $value = $field->dehydrate($validator->validated()[$field->name] ?? null);

        try {
            RecordWriter::write($resource, $record, [$field], [$field->name => $value]);
        } catch (QueryException $exception) {
            return $this->failed($request, $resource, DatabaseErrors::explain($exception), 422);
        } catch (OperationCancelled) {
            return $this->failed($request, $resource, __('fastleo::resources.save_cancelled'), 422);
        }

        return $request->expectsJson()
            ? response()->json(['value' => $value])
            : back(fallback: route('fastleo.resources.index', $resource::slug()))->with('fastleo.success', __('fastleo::resources.saved'));
    }

    private function failed(Request $request, Resource $resource, string $message, int $status): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message], $status)
            : back(fallback: route('fastleo.resources.index', $resource::slug()))->with('fastleo.error', $message);
    }
}
