<?php

declare(strict_types=1);

namespace Fastleo\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Фильтр «Все / Да / Нет» по логической колонке. «Нет» показывает и записи без значения (NULL).
 *
 * ```php
 * BooleanFilter::make('is_published', 'Опубликовано');
 * ```
 */
class BooleanFilter extends Filter
{
    /**
     * `1` → true, `0` → false, остальное — null (фильтр выключен).
     */
    #[Override]
    public function value(mixed $input): ?bool
    {
        return match ($input) {
            '1' => true,
            '0' => false,
            default => null,
        };
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::filters.boolean';
    }

    /**
     * @param  Builder<Model>  $query
     */
    #[Override]
    protected function query(Builder $query, mixed $value): void
    {
        $column = $query->qualifyColumn($this->name);

        if ($value === true) {
            $query->where($column, true);

            return;
        }

        $query->where(fn (Builder $where) => $where->where($column, false)->orWhereNull($column));
    }
}
