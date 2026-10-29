<?php

declare(strict_types=1);

namespace Fastleo\Layout;

use Fastleo\Fields\Field;
use Override;

/**
 * Вкладка внутри `Tabs`.
 *
 * ```php
 * Tab::make('SEO', [Text::make('meta_title'), Textarea::make('meta_description')]);
 * ```
 */
final class Tab extends Layout
{
    /**
     * @param  list<Field|Layout>  $children
     */
    public function __construct(public readonly string $label, array $children = [])
    {
        parent::__construct($children);
    }

    /**
     * Создать вкладку.
     *
     * @param  list<Field|Layout>  $children
     */
    public static function make(string $label, array $children): self
    {
        return new self($label, $children);
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::form.tab';
    }
}
