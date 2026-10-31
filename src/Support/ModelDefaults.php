<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Color;
use Fastleo\Fields\Date;
use Fastleo\Fields\Field;
use Fastleo\Fields\Number;
use Fastleo\Fields\Select;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Time;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Значения полей новой записи, которые не заданы в `->default()` поля: из `$attributes` модели, затем из DEFAULT колонки
 * (`menu tinyint DEFAULT 1` — галочка в форме создания включена). Выражения вроде `CURRENT_TIMESTAMP` пропускаются.
 *
 * ```php
 * ModelDefaults::for($resource); // ['menu' => true, 'sort' => 0]
 * ```
 */
final class ModelDefaults
{
    /** Выражения в DEFAULT, которые не значение. */
    private const array EXPRESSIONS = ['NULL', 'CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME', 'LOCALTIMESTAMP', 'LOCALTIME', 'NOW', 'TRUE', 'FALSE'];

    /**
     * Значения формы по именам полей; поля с `->default()`, без колонки и без значения по умолчанию не попадают.
     *
     * @return array<string, mixed>
     */
    public static function for(Resource $resource): array
    {
        $model = $resource->newModel();
        $columns = self::columnDefaults($model);

        $model->setRawAttributes([...$columns, ...$model->getAttributes()]);

        $values = [];

        foreach ($resource->schema()->fields() as $field) {
            if (! self::applies($field, $model)) {
                continue;
            }

            try {
                $value = $field->hydrate($model);
            } catch (Throwable) {
                continue; // значение базы не подходит касту модели (enum без такого варианта, шифрование) — поле остаётся пустым
            }

            if ($value !== null) {
                $values[$field->name] = $value;
            }
        }

        return $values;
    }

    /**
     * Значения формы создания из адреса `?prefill[поле]=значение`: ссылка «Добавить» у связанной таблицы подставляет
     * родителя, «Создать клиента» — телефон. Берутся только строки для видимых и доступных на запись простых полей
     * (текст, число, дата, время, цвет, список, `BelongsTo`) и только те, что прошли бы проверку формы: вариант вне
     * списка, запись вне `query()` поля, чужая дата, значение длиннее предела пропускаются без ошибки. Пароли, файлы,
     * вложенные записи, множественный выбор, многоязычные поля и галочки не заполняются.
     *
     * ```php
     * ModelDefaults::prefill($resource, $request->query('prefill')); // ['client_id' => '5', 'phone' => '79001234567']
     * ```
     *
     * @return array<string, string>
     */
    public static function prefill(Resource $resource, mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $values = [];

        foreach ($resource->writableFields(null) as $field) {
            $value = $input[$field->name] ?? null;

            if (! is_string($value) || $value === '' || ! self::prefillable($field) || ! self::passes($field, $value)) {
                continue;
            }

            $values[$field->name] = $value;
        }

        return $values;
    }

    /**
     * Простое поле: одно значение-строка, без зависимости от других полей формы.
     */
    private static function prefillable(Field $field): bool
    {
        return $field->writesAttribute()
            && ! $field->isTranslatable()
            && ! $field->isMultiple()
            && $field->getDependsOn() === null
            && ($field instanceof Text || $field instanceof Textarea || $field instanceof Number || $field instanceof Color
                || $field instanceof Date || $field instanceof Time || $field instanceof Select
                || $field instanceof BelongsTo);
    }

    /**
     * Значение прошло бы проверку поля в форме создания.
     */
    private static function passes(Field $field, string $value): bool
    {
        return ! Validator::make([$field->name => $value], [$field->name => $field->getRules(null)[$field->name] ?? []])->fails();
    }

    /**
     * Поле берёт значение модели: пишет один атрибут, не многоязычное, без своего `default()`, значение у модели есть.
     */
    private static function applies(Field $field, Model $model): bool
    {
        return $field->writesAttribute()
            && ! $field->hasDefault()
            && ! $field->isTranslatable()
            && array_key_exists($field->name, $model->getAttributes());
    }

    /**
     * DEFAULT колонок как значения (строки): имя колонки → значение.
     *
     * @return array<string, string>
     */
    private static function columnDefaults(Model $model): array
    {
        try {
            $columns = $model->getConnection()->getSchemaBuilder()->getColumns($model->getTable());
        } catch (Throwable) {
            return [];
        }

        $defaults = [];

        foreach ($columns as $column) {
            $value = is_string($column['default'] ?? null) ? self::literal($column['default']) : null;

            if ($value !== null) {
                $defaults[$column['name']] = $value;
            }
        }

        return $defaults;
    }

    /**
     * Значение из текста DEFAULT (`'abc'`, `1`, `true`, `'x'::character varying`); выражение — null.
     */
    public static function literal(string $default): ?string
    {
        $value = trim((string) preg_replace('/::[A-Za-z_ ]+(\([0-9, ]+\))?(\[\])?$/', '', trim($default)));

        if (str_starts_with($value, '0000-00-00')) {
            return null; // нулевая дата старого MySQL — не значение
        }

        if (preg_match("/^'(.*)'$/s", $value, $match) === 1) {
            $value = str_replace("''", "'", $match[1]);

            return str_starts_with($value, '0000-00-00') ? null : $value;
        }

        if (in_array(strtoupper($value), ['TRUE', 'FALSE'], true)) {
            return strtoupper($value) === 'TRUE' ? '1' : '0';
        }

        if (is_numeric($value)) {
            return $value;
        }

        $isWord = preg_match('/^[\p{L}\p{N} _.:-]+$/u', $value) === 1;

        return $isWord && ! in_array(strtoupper($value), self::EXPRESSIONS, true) ? $value : null;
    }
}
