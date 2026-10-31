<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fastleo;
use Fastleo\Support\CacheCleaner;
use Fastleo\Widgets\DashboardGrid;
use Fastleo\Widgets\DashboardLayout;
use Fastleo\Widgets\Widget;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/**
 * Главная страница админки: виджеты, раскладка администратора и очистка кеша.
 */
final class DashboardController
{
    /**
     * Дашборд: виджеты, видимые администратору, в его раскладке. Данные считаются только у нескрытых виджетов.
     */
    public function __invoke(): View
    {
        $widgets = array_map(
            fn (array $item): array => $item + ['data' => $item['hidden'] ? ['failed' => false] : $item['widget']->resolve()],
            DashboardGrid::build(Fastleo::dashboard(), Fastleo::guard()->user()),
        );

        return view('fastleo::dashboard', [
            'widgets' => $widgets,
            'hasChart' => array_any($widgets, fn (array $item): bool => ! $item['hidden'] && $item['widget']->view() === 'fastleo::widgets.chart' && ! $item['data']['failed']),
        ]);
    }

    /**
     * Сохранить раскладку администратора: порядок, размер и скрытие виджетов. В базу попадают только виджеты, которые
     * этому администратору видны; одинаковые ключи отбрасываются.
     */
    public function saveLayout(Request $request): Response
    {
        $input = Validator::make($request->all(), [
            'widgets' => ['required', 'array', 'max:100'],
            'widgets.*' => ['array:key,cols,rows,hidden'],
            'widgets.*.key' => ['required', 'string', 'regex:/^[a-z0-9-]{1,100}$/'],
            'widgets.*.cols' => ['required', 'integer', 'between:1,4'],
            'widgets.*.rows' => ['required', 'integer', 'between:1,4'],
            'widgets.*.hidden' => ['required', 'boolean'],
        ])->validate();

        $visible = array_filter(DashboardGrid::keyed(Fastleo::dashboard()), fn (Widget $widget): bool => $widget->isVisible());
        $layout = [];

        foreach (array_filter(Arr::array($input, 'widgets'), is_array(...)) as $entry) {
            $key = Arr::string($entry, 'key');

            if (isset($visible[$key]) && ! isset($layout[$key])) {
                $layout[$key] = [
                    'key' => $key,
                    'cols' => $this->number($entry['cols']),
                    'rows' => $this->number($entry['rows']),
                    'hidden' => filter_var($entry['hidden'], FILTER_VALIDATE_BOOLEAN),
                ];
            }
        }

        DashboardLayout::query()->updateOrCreate(['user_id' => $this->userKey()], ['layout' => array_values($layout)]);

        return response()->noContent();
    }

    /**
     * Вернуть раскладку из кода сайта: настройки администратора удаляются.
     */
    public function resetLayout(): RedirectResponse
    {
        DashboardLayout::query()->where('user_id', $this->userKey())->delete();

        return to_route('fastleo.dashboard');
    }

    /**
     * Очистить кеш приложения, шаблонов и список разделов (`CacheCleaner`, без кеша конфигурации и маршрутов) и сообщить, сколько места освободилось.
     */
    public function clearCache(): RedirectResponse
    {
        abort_unless(CacheCleaner::canClear(), 403);

        $freed = CacheCleaner::clear();

        return to_route('fastleo.dashboard')->with('fastleo.success', __('fastleo::dashboard.cache_cleared', ['size' => CacheCleaner::format($freed)]));
    }

    /**
     * Ключ вошедшего администратора в таблице раскладок.
     */
    private function userKey(): string
    {
        $key = Fastleo::guard()->id();

        return is_scalar($key) ? (string) $key : '';
    }

    /**
     * Размер виджета из запроса: число или число текстом (форма и запрос, собранный вручную, присылают строку).
     */
    private function number(mixed $value): int
    {
        return is_scalar($value) ? (int) $value : 1;
    }
}
