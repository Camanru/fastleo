<?php

declare(strict_types=1);

namespace Fastleo\Layout;

use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Related;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Override;

/**
 * Вкладка внутри `Tabs`: либо поля формы, либо связанные таблицы (`Related`). Таблица показывается вне формы и не
 * сохраняется вместе с ней, поэтому вкладка с таблицами не смешивается с полями.
 *
 * ```php
 * Tab::make('SEO', [Text::make('meta_title'), Textarea::make('meta_description')]);
 * Tab::make('Отзывы', [Related::make('reviews', ReviewResource::class)->editIn('modal')]);
 * ```
 */
final class Tab extends Layout
{
    private int $number = 0;

    /**
     * @param  list<Field|Layout|Related>  $children
     *
     * @throws InvalidArgumentException в одной вкладке и поля, и связанные таблицы
     */
    public function __construct(public readonly string $label, array $children = [])
    {
        $tables = count(array_filter($children, fn (mixed $child): bool => $child instanceof Related));

        if ($tables > 0 && $tables !== count($children)) {
            throw new InvalidArgumentException(__('fastleo::exceptions.tab_mixed', ['tab' => $label], Fastleo::LOCALE));
        }

        parent::__construct($children);
    }

    /**
     * Создать вкладку.
     *
     * @param  list<Field|Layout|Related>  $children
     */
    public static function make(string $label, array $children): self
    {
        return new self($label, $children);
    }

    /**
     * Связанные таблицы вкладки (пусто у вкладки с полями).
     *
     * @return list<Related>
     */
    public function tables(): array
    {
        return array_values(array_filter($this->children, fn (mixed $child): bool => $child instanceof Related));
    }

    /**
     * Во вкладке таблицы, а не поля формы.
     */
    public function isTable(): bool
    {
        return $this->tables() !== [];
    }

    /**
     * Ключ вкладки для адреса (`#otzyvy`): из названия; если в названии нет букв и цифр (одни эмодзи) — `tab-2` по номеру
     * вкладки в `Tabs`.
     */
    public function key(): string
    {
        return Str::slug($this->label) ?: 'tab'.($this->number > 0 ? '-'.$this->number : '');
    }

    /**
     * Номер вкладки в `Tabs` (с единицы) для запасного ключа адреса; вызывает `Tabs`.
     */
    public function numbered(int $number): void
    {
        $this->number = $number;
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::form.tab';
    }
}
