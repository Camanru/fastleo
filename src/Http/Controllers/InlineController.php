<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Fastleo\Support\DatabaseErrors;
use Fastleo\Support\InlineEdit;
use Fastleo\Support\OperationCancelled;
use Fastleo\Support\RecordKey;
use Fastleo\Support\RecordWriter;
use Fastleo\Support\ValidationMessages;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Правка значения в ячейке списка: меняет одно поле записи без открытия формы.
 *
 * Работает только для поля с `inlineEditable()`. Проверяется то же, что при сохранении формы: право `update` на запись
 * (в разделе `$readonly` его нет), поле видно и не `readonly()`, правила этого поля — с остальными значениями записи
 * в данных, чтобы правила вроде `different:other` видели настоящие значения. Правила других полей не проверяются:
 * старая запись, не проходящая чужое правило, не блокирует правку ячейки. Запись идёт через `RecordWriter` и Eloquent
 * (observers, журнал изменений).
 */
final class InlineController
{
    /**
     * `PATCH /fastleo/{resource}/{id}/inline/{field}` с `value` → `{"value": "…", "display": "…", "cells": {поле: html}}`
     * (`cells` — заново посчитанные вычисляемые столбцы строки: маржа, сумма; страница подставляет их в строку);
     * ошибка — `{"message": "…"}` с кодом 422.
     */
    public function __invoke(Request $request, string $resource, string $id, string $field): JsonResponse
    {
        $resource = Fastleo::resources()->find($resource);
        abort_unless($resource instanceof Resource, 404);

        abort_unless(RecordKey::valid($resource->newModel(), $id), 404);
        $record = $resource->newQuery()->whereKey($id)->first();
        abort_unless($record instanceof Model, 404);
        Authorization::authorize('update', $resource, $record);

        $field = $resource->schema()->field($field);
        abort_unless($field instanceof Field && $field->isInlineEditable(), 404);
        abort_unless(in_array($field, $resource->writableFields($record), true) && $field->isConditionMet($record), 403);

        // Без значения «очистить поле» означало бы то же, что «не менять»: ячейка всегда присылает значение явно.
        $value = $request->input('value');
        abort_unless($request->has('value') && ($value === null || (is_scalar($value) && ! is_bool($value) && strlen((string) $value) <= InlineEdit::MAX_LENGTH)), 422);

        // Значения остальных простых полей нужны правилам вроде `different:other`.
        $data = [...RecordWriter::currentValues($resource, $record), $field->name => $value];

        $validator = validator(
            $data,
            RecordWriter::rules($resource, $record, [$field]),
            ValidationMessages::all(),
            $resource->validationAttributes($record),
        );

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        try {
            RecordWriter::write($resource, $record, [$field], [$field->name => $validator->validated()[$field->name] ?? null]);
        } catch (QueryException $exception) {
            return response()->json(['message' => DatabaseErrors::explain($exception)], 422);
        } catch (OperationCancelled) {
            return response()->json(['message' => __('fastleo::resources.save_cancelled')], 422);
        }

        $saved = $field->hydrate($record);
        $display = $field->display($record);

        return response()->json([
            'value' => is_scalar($saved) ? (string) $saved : '',
            'display' => $display instanceof Htmlable ? strip_tags($display->toHtml()) : (string) $display,
            'cells' => $this->computedCells($resource, $id),
        ]);
    }

    /**
     * Вычисляемые столбцы (`fromQuery()`) записи после правки: значение берётся из `query()` ресурса заново.
     *
     * @return array<string, string>
     */
    private function computedCells(Resource $resource, string $id): array
    {
        $record = $resource->newQuery()->whereKey($id)->first();
        $cells = [];

        if (! $record instanceof Model) {
            return $cells;
        }

        foreach ($resource->listFields() as $field) {
            if ($field->isFromQuery()) {
                $cells[$field->name] = view('fastleo::resources.cell', ['resource' => $resource, 'record' => $record, 'field' => $field, 'inList' => true])->render();
            }
        }

        return $cells;
    }
}
