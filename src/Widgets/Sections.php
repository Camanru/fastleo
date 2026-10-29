<?php

declare(strict_types=1);

namespace Fastleo\Widgets;

use Fastleo\Fastleo;
use Fastleo\Support\Menu;
use Override;

/**
 * Плитки разделов сайта с иконками и счётчиками (`badge()`). Показываются те же разделы, что и в меню,
 * с учётом Policy.
 */
final class Sections extends Widget
{
    #[Override]
    public function view(): string
    {
        return 'fastleo::widgets.sections';
    }

    #[Override]
    protected function defaultTitle(): string
    {
        return __('fastleo::dashboard.sections', [], Fastleo::LOCALE);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function payload(): array
    {
        $items = [];

        foreach (Menu::groups() as $group) {
            foreach ($group['items'] as $item) {
                $items[] = $item;
            }
        }

        return ['items' => $items];
    }
}
