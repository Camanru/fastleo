<?php

declare(strict_types=1);

namespace Fastleo\Support;

use BackedEnum;
use Closure;
use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Field;
use Fastleo\Fields\Nested;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Проверка и запись полей ресурса в модель — общее у формы и импорта из файла.
 *
 * Порядок записи: в транзакции `dehydrate` → `fill` полей, пришедших в данных, → `beforeSave` →
 * новая запись в конец ручного порядка → `save()` → `afterSave` (связи, вложенные записи, файлы).
 *
 * ```php
 * $rules = RecordWriter::rules($resource, $record, $fields);
 * RecordWriter::write($resource, $record, $fields, $validated);
 * ```
 */
final class RecordWriter
{
    /**
     * Значения простых полей записи, как их отправляет форма: нужны правилам вроде `different:other`, когда проверяется одно поле.
     * Скаляры — строкой (`true` → `'1'`, `5` → `'5'`, enum → значение), чтобы `same:`/`different:` сравнивали как в форме.
     * Вложенные записи, связи «многие ко многим» и переводы не читаются; поле, значение которого не читается
     * (исключение в `hydrate()`), пропускается — правила видят его пустым.
     *
     * ```php
     * $data = [...RecordWriter::currentValues($resource, $record), 'status' => $value];
     * ```
     *
     * @return array<string, mixed>
     */
    public static function currentValues(Resource $resource, Model $record): array
    {
        $values = [];
        $fields = array_filter(
            $resource->writableFields($record),
            fn (Field $field): bool => ! $field instanceof Nested && ! $field instanceof BelongsToMany && ! $field->isTranslatable(),
        );

        foreach ($fields as $field) {
            try {
                $value = $field->hydrate($record);
            } catch (Throwable) {
                continue;
            }

            $values[$field->name] = is_scalar($value) || $value instanceof BackedEnum ? Field::conditionValue($value) : $value;
        }

        return $values;
    }

    /**
     * Правила полей; в дереве — запрет сделать родителем саму запись или её потомка.
     *
     * @param  list<Field>  $fields
     * @return array<string, list<mixed>>
     */
    public static function rules(Resource $resource, ?Model $existing, array $fields): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $rules = [...$rules, ...$field->getRules($existing)];
        }

        $tree = $resource::treeColumn();

        if ($tree !== null && $existing instanceof Model && isset($rules[$tree])) {
            $rules[$tree][] = function (string $attribute, mixed $value, Closure $fail) use ($resource, $existing, $tree): void {
                // Значение приводится так же, как его запишет поле: «+2» и «02» — это запись 2.
                $candidate = is_numeric($value) && $existing->getKeyType() === 'int' ? (string) (int) $value : (is_scalar($value) ? (string) $value : '');

                if ($existing->getKeyType() !== 'int' && $candidate !== '') {
                    // Строковый ключ (UUID, ULID): пробел или другой регистр не должны обходить проверку, поэтому берём ключ, как его хранит база.
                    $candidate = self::storedKey($resource, $candidate);
                }
                $key = $existing->getKey();

                if ($candidate !== '' && Tree::load($resource->newModel()->newQueryWithoutScopes(), $tree, null)->isSelfOrDescendant(is_scalar($key) ? (string) $key : '', $candidate)) {
                    $fail(__('fastleo::resources.tree_cycle'));
                }
            };
        }

        return $rules;
    }

    /**
     * Ключ записи так, как его хранит база, или пустая строка, если такой записи нет.
     */
    private static function storedKey(Resource $resource, string $candidate): string
    {
        $model = $resource->newModel();

        try {
            $stored = $model->newQueryWithoutScopes()->whereKey($candidate)->value($model->getKeyName());
        } catch (QueryException) {
            return '';
        }

        return is_scalar($stored) ? (string) $stored : '';
    }

    /**
     * Подписи полей для текстов ошибок.
     *
     * @param  list<Field>  $fields
     * @return array<string, string>
     */
    public static function attributes(array $fields): array
    {
        $attributes = [];

        foreach ($fields as $field) {
            $attributes = [...$attributes, ...$field->validationAttributes()];
        }

        return $attributes;
    }

    /**
     * Значения по умолчанию (`default()` поля) для полей новой записи, которых нет во вводе: форма их не прислала
     * (поле скрыто, запрос не из формы, MCP). Без этого `required()` поле со значением по умолчанию не прошло бы проверку.
     * Присланное значение, в том числе пустое, не трогается. Берутся только простые значения; связи и вложенные записи
     * отдают объекты — они не данные.
     *
     * ```php
     * $data = RecordWriter::withDefaults($resource->writableFields(null), $request->all());
     * ```
     *
     * @param  list<Field>  $fields
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function withDefaults(array $fields, array $data): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field->name, $data)) {
                continue;
            }

            $default = $field->formValue(null);

            if ((is_scalar($default) && $default !== '') || (is_array($default) && $default !== [])) {
                $data[$field->name] = $default;
            }
        }

        return $data;
    }

    /**
     * Ввод формы, в котором управляющие поля (`visibleWhen`, `dependsOn`), недоступные админу
     * для изменения, заменены сохранённым значением (при создании — значением по умолчанию).
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function controllingInput(Resource $resource, ?Model $existing, array $input): array
    {
        $writable = array_map(fn (Field $field): string => $field->name, $resource->writableFields($existing));

        foreach ($resource->schema()->fields() as $field) {
            foreach (array_filter([$field->getVisibleWhen()['field'] ?? null, $field->getDependsOn()]) as $controlling) {
                if (in_array($controlling, $writable, true)) {
                    continue;
                }

                $input[$controlling] = $resource->controllingValue($controlling, $existing, null);
            }
        }

        return $input;
    }

    /**
     * `validated()` собирает массивы в порядке правил (`links.*.id` раньше `links.*.title`), а не формы.
     * Порядок ключей верхних массивов (вложенные блоки, метки) возвращается как во вводе.
     *
     * @param  array<array-key, mixed>  $validated
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function inInputOrder(array $validated, array $input): array
    {
        foreach ($validated as $key => $value) {
            $raw = $input[$key] ?? null;

            if (is_array($value) && is_array($raw)) {
                $validated[$key] = array_replace(array_intersect_key($raw, $value), $value);
            }
        }

        return $validated;
    }

    /**
     * Записать проверенные значения полей в модель и сохранить в одной транзакции.
     * Поле, которого нет в данных, не трогается.
     *
     * @param  list<Field>  $fields
     * @param  array<array-key, mixed>  $data
     *
     * @throws QueryException ошибка базы данных
     * @throws OperationCancelled модель отменила сохранение (событие `saving` вернуло false)
     */
    public static function write(Resource $resource, Model $record, array $fields, array $data): void
    {
        $resource->assertFillable($record, $fields);

        DB::connection($record->getConnectionName())->transaction(function () use ($fields, $data, $record, $resource): void {
            $values = [];

            foreach ($fields as $field) {
                if (array_key_exists($field->name, $data)) {
                    $values[$field->name] = $field->dehydrate($data[$field->name]);
                    $field->fill($record, $values[$field->name]);
                }
            }

            foreach ($fields as $field) {
                $field->beforeSave($record);
            }

            self::placeAtEnd($resource, $record);

            if (! $record->save()) {
                throw new OperationCancelled;
            }

            foreach ($fields as $field) {
                if (array_key_exists($field->name, $values)) {
                    $field->afterSave($record, $values[$field->name]);
                }
            }
        });
    }

    /**
     * Запрос записей с тем же родителем, что у записи (без дерева — все записи).
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function siblingsQuery(Resource $resource, Model $record, Builder $query): Builder
    {
        $tree = $resource::treeColumn();

        if ($tree === null) {
            return $query;
        }

        $parent = $record->getAttribute($tree);

        return $parent === null ? $query->whereNull($query->qualifyColumn($tree)) : $query->where($query->qualifyColumn($tree), $parent);
    }

    /**
     * Поставить запись в конец порядка среди соседей (восстановленная из корзины не должна занять чужой номер).
     * Раздел без ручного порядка не затрагивается.
     */
    public static function moveToEnd(Resource $resource, Model $record): void
    {
        $column = $resource::sortColumn();

        if ($column === null) {
            return;
        }

        $last = self::siblingsQuery($resource, $record, $resource->newModel()->newQuery()->withoutGlobalScopes())
            ->whereKeyNot($record->getKey())
            ->max($column);

        $record->forceFill([$column => (is_numeric($last) ? (int) $last : 0) + 1])->save();
    }

    /**
     * Новая запись (или перенесённая в другую ветку) встаёт в конец соседей, если порядок не задан.
     */
    private static function placeAtEnd(Resource $resource, Model $record): void
    {
        $column = $resource::sortColumn();
        $tree = $resource::treeColumn();

        if ($column === null) {
            return;
        }

        // Пустое значение порядка (поле очистили) — то же, что «не задан»: запись встаёт в конец, а не пишет NULL.
        $empty = in_array($record->getAttribute($column), [null, ''], true);

        if (! $empty && ($record->isDirty($column) || ($record->exists && ($tree === null || ! $record->isDirty($tree))))) {
            return;
        }

        $last = self::siblingsQuery($resource, $record, $resource->newModel()->newQuery())->max($column);
        $record->setAttribute($column, (is_numeric($last) ? (int) $last : 0) + 1);
    }
}
