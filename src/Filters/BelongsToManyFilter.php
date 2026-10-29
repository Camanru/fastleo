<?php

declare(strict_types=1);

namespace Fastleo\Filters;

use Fastleo\Fastleo;
use Fastleo\Fields\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Override;

/**
 * Фильтр по связи «многие ко многим»: записи, у которых среди связанных есть выбранная. Варианты и подписи —
 * из одноимённого поля `BelongsToMany` ресурса (имя фильтра = имя поля = метод связи модели), поиск и
 * предел `fastleo.relations.preload` — как у `BelongsToFilter`.
 *
 * ```php
 * BelongsToManyFilter::make('topics', 'Тема');
 * ```
 */
class BelongsToManyFilter extends BelongsToFilter
{
    /**
     * Поле связи с тем же именем.
     *
     * @throws LogicException в ресурсе нет поля BelongsToMany с именем фильтра
     */
    #[Override]
    public function relation(): BelongsToMany
    {
        $field = $this->field();

        return $field instanceof BelongsToMany ? $field : throw new LogicException(__('fastleo::exceptions.filter_belongs_to_many', ['filter' => $this->name], Fastleo::LOCALE));
    }

    /**
     * @param  Builder<Model>  $query
     */
    #[Override]
    protected function query(Builder $query, mixed $value): void
    {
        $query->whereHas($this->relation()->getRelationName(), fn (Builder $related): Builder => $related->whereKey($value));
    }
}
