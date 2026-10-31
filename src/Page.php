<?php

declare(strict_types=1);

namespace Fastleo;

use Fastleo\Reports\Filters;
use Fastleo\Reports\ReportFilter;
use Fastleo\Widgets\Chart;
use Fastleo\Widgets\Widget;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Своя страница админки без модели: отчёт, инструкция, панель управления. Появляется в меню.
 * Класс лежит рядом с ресурсами (по умолчанию `app/Fastleo/`), шаблон — в `resources/views` сайта.
 *
 * ```php
 * namespace App\Fastleo;
 *
 * final class ReportPage extends \Fastleo\Page
 * {
 *     protected static ?string $label = 'Отчёт по заявкам';
 *     protected static string $icon = 'chart-bar';
 *
 *     public function view(): string
 *     {
 *         return 'fastleo.report';   // resources/views/fastleo/report.blade.php
 *     }
 *
 *     public function data(Request $request): array
 *     {
 *         return ['total' => Lead::count()];
 *     }
 * }
 * ```
 *
 * ```blade
 * <x-fastleo::layouts.app title="Отчёт по заявкам">
 *     <x-fastleo::card title="Всего заявок">{{ $total }}</x-fastleo::card>
 * </x-fastleo::layouts.app>
 * ```
 *
 * Адрес — `/fastleo/pages/{slug}`; класс `ReportPage` → `report`. Форма на своей странице отправляется на маршрут
 * сайта, объявленный через `Fastleo::routes()` (с защитой админки и CSRF).
 */
abstract class Page
{
    /** Название страницы в меню. По умолчанию — имя класса без «Page». */
    protected static ?string $label = null;

    /** Иконка Lucide в меню. */
    protected static string $icon = 'file-text';

    /** Группа меню; по умолчанию без группы. */
    protected static ?string $group = null;

    /** Позиция в меню внутри группы. */
    protected static int $position = 0;

    /** Колонок в сетке виджетов страницы-отчёта: 4 или 6 (шесть плиток в ряд). */
    protected static int $columns = 4;

    /** Часть адреса `/fastleo/pages/{slug}`. По умолчанию — kebab имени класса без «Page». */
    protected static ?string $slug = null;

    /**
     * Колонок в сетке виджетов отчёта: 6, если так задано в `$columns`, иначе 4. Широкие экраны показывают столько плиток
     * в ряд; виджет шире сетки занимает всю строку.
     */
    public static function columns(): int
    {
        return static::$columns === 6 ? 6 : 4;
    }

    /**
     * Blade-шаблон страницы (в нём подключается `<x-fastleo::layouts.app>`). Без своего шаблона страница — отчёт:
     * панель фильтров (`filters()`) и сетка виджетов (`widgets()`).
     */
    public function view(): string
    {
        return 'fastleo::pages.report';
    }

    /**
     * Данные для шаблона. Для отчёта (шаблон по умолчанию) — фильтры и посчитанные виджеты.
     *
     * @return array<string, mixed>
     */
    public function data(Request $request): array
    {
        $items = $this->filters();
        $filters = Filters::resolve($items, $request);
        $widgets = [];
        $hasChart = false;

        foreach ($this->widgets($filters) as $widget) {
            if ($widget->isVisible()) {
                $widgets[] = ['widget' => $widget, 'data' => $widget->resolve()];
                $hasChart = $hasChart || $widget instanceof Chart;
            }
        }

        return ['page' => $this, 'items' => $items, 'filters' => $filters, 'widgets' => $widgets, 'hasChart' => $hasChart];
    }

    /**
     * Фильтры страницы-отчёта: период, список. Значения приходят в `widgets()`.
     *
     * ```php
     * public function filters(): array
     * {
     *     return [Period::make('period', 'Период')->default('month'), Choice::make('manager', 'Менеджер')->options([1 => 'Анна'])];
     * }
     * ```
     *
     * @return list<ReportFilter>
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * Виджеты страницы-отчёта (`Stat`, `Chart`, `Rows`, `Table`, свои): значения фильтров берутся в замыканиях.
     *
     * ```php
     * public function widgets(Filters $filters): array
     * {
     *     $period = $filters->period('period');
     *
     *     return [Stat::make('Заявок')->value(fn () => $period->apply(Lead::query(), 'created_at')->count())];
     * }
     * ```
     *
     * @return list<Widget>
     */
    public function widgets(Filters $filters): array
    {
        return [];
    }

    /**
     * Страница сейчас существует: если `false`, её нет в меню и поиске, а адрес отвечает 404 (а не «нет доступа»).
     * По умолчанию — да; нужен страницам, которые появляются при условии (например, подключение к сервису).
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Администратору можно открывать страницу. По умолчанию — всем администраторам.
     */
    public function canAccess(?Authenticatable $user): bool
    {
        return true;
    }

    /**
     * Название страницы.
     */
    public static function label(): string
    {
        return static::$label ?? Str::headline(self::baseName());
    }

    /**
     * Иконка в меню.
     */
    public static function icon(): string
    {
        return static::$icon;
    }

    /**
     * Группа меню или null.
     */
    public static function group(): ?string
    {
        return static::$group;
    }

    /**
     * Позиция в меню.
     */
    public static function position(): int
    {
        return static::$position;
    }

    /**
     * Часть адреса страницы.
     */
    public static function slug(): string
    {
        return static::$slug ?? Str::kebab(self::baseName());
    }

    /**
     * Имя класса без слова «Page».
     */
    private static function baseName(): string
    {
        $name = class_basename(static::class);

        return Str::replaceEnd('Page', '', $name) ?: $name;
    }
}
