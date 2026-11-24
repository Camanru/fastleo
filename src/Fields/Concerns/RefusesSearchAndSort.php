<?php

declare(strict_types=1);

namespace Fastleo\Fields\Concerns;

use Fastleo\Fastleo;
use LogicException;
use Override;

/**
 * Поле-связь без своей колонки в таблице раздела (`BelongsToMany`, `HasMany`, `HasOne`): поиск и сортировка по нему не поддерживаются.
 */
trait RefusesSearchAndSort
{
    /**
     * @throws LogicException колонки с именем связи в таблице нет
     */
    #[Override]
    public function sortable(bool $sortable = true): static
    {
        return $sortable ? throw $this->noColumn() : $this;
    }

    /**
     * @throws LogicException колонки с именем связи в таблице нет
     */
    #[Override]
    public function searchable(bool $searchable = true): static
    {
        return $searchable ? throw $this->noColumn() : $this;
    }

    /**
     * Ошибка описания раздела: у поля-связи нет колонки для поиска и сортировки.
     */
    private function noColumn(): LogicException
    {
        return new LogicException(__('fastleo::exceptions.relation_search_sort', ['field' => $this->name, 'type' => class_basename($this)], Fastleo::LOCALE));
    }
}
