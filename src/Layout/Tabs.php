<?php

declare(strict_types=1);

namespace Fastleo\Layout;

use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Вкладки формы. Открыта первая вкладка; после ошибки проверки — первая вкладка с ошибкой.
 *
 * ```php
 * Tabs::make([
 *     Tab::make('Основное', [Text::make('title')->required()]),
 *     Tab::make('SEO', [Text::make('meta_title')]),
 * ]);
 * ```
 */
final class Tabs extends Layout
{
    /**
     * Вкладки по порядку. Не-вкладка внутри — ошибка типа PHP (поле не потеряется молча).
     */
    public function __construct(Tab ...$tabs)
    {
        parent::__construct(array_values($tabs));
    }

    /**
     * Создать вкладки.
     *
     * @param  list<Tab>  $tabs
     */
    public static function make(array $tabs): self
    {
        return new self(...$tabs);
    }

    /**
     * Вкладки, в которых есть видимые поля.
     *
     * @return list<Tab>
     */
    public function visibleTabs(?Model $record): array
    {
        return array_values(array_filter($this->children, fn (mixed $tab): bool => $tab instanceof Tab && $tab->hasVisibleFields($record)));
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::form.tabs';
    }
}
