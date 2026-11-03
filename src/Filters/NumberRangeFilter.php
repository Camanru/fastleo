<?php

declare(strict_types=1);

namespace Fastleo\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Фильтр по числам «от … до …» (цена, год, наработка): обе границы включительно, любую можно не указывать.
 *
 * Значение из адреса — `?filter[price][from]=100&filter[price][to]=500`. Границы — целые или десятичные числа
 * (разделитель точка или запятая); всё остальное отбрасывается, а если не осталось ни одной границы, фильтр выключен.
 *
 * ```php
 * NumberRangeFilter::make('price', 'Цена');
 * NumberRangeFilter::make('year', 'Год выпуска')->default(['from' => 2020]);
 * ```
 */
class NumberRangeFilter extends Filter
{
    /**
     * Границы строкой (`'100'`, `'-2.5'`) или null, если нет ни одной допустимой.
     *
     * @return array{from: ?string, to: ?string}|null
     */
    #[Override]
    public function value(mixed $input): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        $from = $this->number($input['from'] ?? null);
        $to = $this->number($input['to'] ?? null);

        return $from === null && $to === null ? null : ['from' => $from, 'to' => $to];
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::filters.number-range';
    }

    /**
     * @param  Builder<Model>  $query
     */
    #[Override]
    protected function query(Builder $query, mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }

        $column = $query->qualifyColumn($this->name);
        $range = $this->integerRange($query);

        foreach (['from' => '>=', 'to' => '<='] as $end => $operator) {
            if (! is_string($value[$end] ?? null)) {
                continue;
            }

            $text = $value[$end];

            if ($range !== null) {
                // Целая колонка: PostgreSQL не принимает «100.5» и число шире типа (`integer`), поэтому граница
                // округляется внутрь диапазона (от — вверх, до — вниз) и сжимается до значений типа колонки.
                $number = $end === 'from' ? ceil((float) $text) : floor((float) $text);

                if (($end === 'from' && $number > $range[1]) || ($end === 'to' && $number < $range[0])) {
                    $query->whereRaw('0 = 1');

                    return;
                }

                $query->where($column, $operator, (int) max($range[0], min($range[1], $number)));

                continue;
            }

            // Дробная граница уходит в базу строкой: PHP округлил бы число длиннее 14 значащих цифр.
            $query->where($column, $operator, str_contains($text, '.') ? $text : (int) $text);
        }
    }

    /**
     * Наименьшее и наибольшее значение целой колонки фильтра (по схеме таблицы) или null, если колонка не целая или не найдена.
     *
     * @param  Builder<Model>  $query
     * @return array{int, int}|null
     */
    protected function integerRange(Builder $query): ?array
    {
        $model = $query->getModel();
        $column = array_find($model->getConnection()->getSchemaBuilder()->getColumns($model->getTable()), fn (array $column): bool => $column['name'] === $this->name);

        return $column === null ? null : self::rangeOf($column['type_name'], $column['type']);
    }

    /**
     * Границы целого типа колонки: `smallint` → ±32 767, `int unsigned` → 0…4 294 967 295; `bigint` и `integer` SQLite — 64 бита.
     *
     * ```php
     * NumberRangeFilter::rangeOf('int4', 'integer');   // [-2147483648, 2147483647]
     * ```
     *
     * @return array{int, int}|null
     */
    public static function rangeOf(string $typeName, string $type): ?array
    {
        $bits = match ($typeName) {
            'tinyint' => 8,
            'smallint', 'int2' => 16,
            'mediumint' => 24,
            'int', 'int4' => 32,
            'integer', 'bigint', 'int8' => 64,
            default => null,
        };

        if ($bits === null) {
            return null;
        }

        $unsigned = str_contains($type, 'unsigned');

        return match (true) {
            $bits === 64 => [$unsigned ? 0 : PHP_INT_MIN, PHP_INT_MAX],
            $unsigned => [0, 2 ** $bits - 1],
            default => [-(2 ** ($bits - 1)), 2 ** ($bits - 1) - 1],
        };
    }

    /**
     * Число из адреса в виде строки (точка — разделитель дробной части) или null: не число, слишком длинное.
     * Записи `.5`, `5.` и `1e3` (их отправляет поле `type=number`) приводятся к `0.5`, `5` и `1000`.
     */
    private function number(mixed $input): ?string
    {
        $input = is_string($input) ? str_replace(',', '.', self::string($input) ?? '') : '';

        if (preg_match('/^-?(\d+\.?\d*|\.\d+)[eE][+-]?\d{1,2}$/', $input) === 1) {
            $input = rtrim(rtrim(number_format((float) $input, 10, '.', ''), '0'), '.');
        }

        $input = preg_replace(['/^(-?)\./', '/\.$/'], ['${1}0.', ''], $input) ?? '';

        return preg_match('/^-?\d{1,15}(\.\d{1,10})?$/', $input) === 1 ? $input : null;
    }
}
