<?php

declare(strict_types=1);

namespace Fastleo\Layout;

use Fastleo\Fields\Field;
use Override;

/**
 * Боковая панель справа от формы (на телефоне — под формой): статус, даты, галочки.
 * Указывается на верхнем уровне `fields()`.
 *
 * ```php
 * Aside::make([Toggle::make('is_active'), Date::make('published_on')]);
 * ```
 */
final class Aside extends Layout
{
    /**
     * Создать боковую панель.
     *
     * @param  list<Field|Layout>  $children
     */
    public static function make(array $children): self
    {
        return new self($children);
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::form.aside';
    }
}
