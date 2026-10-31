<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Fields\Checkbox;
use Fastleo\Fields\Color;
use Fastleo\Fields\Date;
use Fastleo\Fields\Field;
use Fastleo\Fields\Number;
use Fastleo\Fields\Select;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Time;

/**
 * Какие поля могут брать значение из `query()` ресурса (`Field::fromQuery()`): простые типы, которые только показываются.
 * Связи, файлы, пароли, вложенные записи и редактор не подходят.
 *
 * ```php
 * QueryColumn::supports(Number::make('offers_count')); // true
 * ```
 */
final class QueryColumn
{
    /**
     * Поле можно сделать вычисляемым столбцом.
     */
    public static function supports(Field $field): bool
    {
        return $field instanceof Text || $field instanceof Textarea || $field instanceof Number || $field instanceof Date
            || $field instanceof Time || $field instanceof Select || $field instanceof Checkbox || $field instanceof Color;
    }
}
