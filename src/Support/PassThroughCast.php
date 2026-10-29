<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Каст «как есть»: значение хранится в атрибуте без преобразований (массив остаётся массивом).
 * Нужен модели настроек без таблицы, чтобы поля Repeater, KeyValue, Tags и другие, требующие у колонки каст,
 * работали с обычными PHP-значениями.
 *
 * @implements CastsAttributes<mixed, mixed>
 */
final class PassThroughCast implements CastsAttributes
{
    /**
     * Значение атрибута.
     *
     * @param  array<string, mixed>  $attributes
     */
    #[\Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }

    /**
     * Значение для записи в атрибут: массив с ключом атрибута, чтобы Eloquent не принял массив за набор атрибутов.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    #[\Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$key => $value];
    }
}
