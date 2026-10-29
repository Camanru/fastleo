<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fastleo;
use Fastleo\Support\CacheCleaner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Главная страница админки: виджеты и очистка кеша.
 */
final class DashboardController
{
    /**
     * Дашборд: виджеты, видимые администратору, с подготовленными данными.
     */
    public function __invoke(): View
    {
        $widgets = [];

        foreach (Fastleo::dashboard()->widgets() as $widget) {
            if ($widget->isVisible()) {
                $widgets[] = ['widget' => $widget, 'data' => $widget->resolve()];
            }
        }

        return view('fastleo::dashboard', [
            'widgets' => $widgets,
            'hasChart' => array_any($widgets, fn (array $item): bool => $item['widget']->view() === 'fastleo::widgets.chart' && ! $item['data']['failed']),
        ]);
    }

    /**
     * Очистить кеш сайта (`optimize:clear`) и сообщить, сколько места освободилось.
     */
    public function clearCache(): RedirectResponse
    {
        abort_unless(CacheCleaner::canClear(), 403);

        $freed = CacheCleaner::clear();

        return to_route('fastleo.dashboard')->with('fastleo.success', __('fastleo::dashboard.cache_cleared', ['size' => CacheCleaner::format($freed)]));
    }
}
