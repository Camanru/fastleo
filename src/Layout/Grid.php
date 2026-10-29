<?php

declare(strict_types=1);

namespace Fastleo\Layout;

use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use InvalidArgumentException;
use Override;

/**
 * Поля в несколько колонок (на телефоне — одна колонка). Ширину поля задаёт `columnSpan()`.
 *
 * ```php
 * Grid::make(3, [Text::make('city')->columnSpan(2), Text::make('zip')]);
 * ```
 */
final class Grid extends Layout
{
    /**
     * @param  list<Field|Layout>  $children
     *
     * @throws InvalidArgumentException колонок не 1–12
     */
    public function __construct(public readonly int $columns, array $children = [])
    {
        if ($columns < 1 || $columns > 12) {
            throw new InvalidArgumentException(__('fastleo::exceptions.grid_columns', ['columns' => $columns], Fastleo::LOCALE));
        }

        parent::__construct($children);
    }

    /**
     * Создать сетку.
     *
     * @param  list<Field|Layout>  $children
     */
    public static function make(int $columns, array $children): self
    {
        return new self($columns, $children);
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::form.grid';
    }
}
