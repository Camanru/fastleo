<?php

declare(strict_types=1);

namespace Fastleo\Widgets;

use Fastleo\Dashboard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Раскладка дашборда: ключи виджетов, порядок и размеры с учётом настроек администратора.
 *
 * Ключи раздаются по всему списку `Dashboard::widgets()` до проверки `visible()`, поэтому не зависят от прав. Сначала идут
 * виджеты в сохранённом порядке, затем новые виджеты из кода; ключи, которых больше нет или которые администратору не
 * видны, отбрасываются.
 */
final class DashboardGrid
{
    /**
     * Виджеты дашборда по ключам в порядке кода. Заданный `key()` не меняется; ключ из класса и заголовка, совпавший
     * с занятым, получает суффикс `-2`, `-3`. Одинаковые заданные ключи — тоже с суффиксом.
     *
     * @return array<string, Widget>
     */
    public static function keyed(Dashboard $dashboard): array
    {
        $widgets = $dashboard->widgets();
        $keys = [];
        $taken = [];

        foreach ($widgets as $index => $widget) {
            $key = $widget->getKey();

            if ($key !== null) {
                $keys[$index] = self::free($key, $taken);
            }
        }

        foreach ($widgets as $index => $widget) {
            if (! isset($keys[$index])) {
                $base = Str::of(class_basename($widget))->slug().'-'.Str::slug($widget->getTitle());
                $keys[$index] = self::free(rtrim(substr(trim($base, '-'), 0, 90), '-'), $taken);
            }
        }

        $result = [];

        foreach ($widgets as $index => $widget) {
            $result[$keys[$index]] = $widget;
        }

        return $result;
    }

    /**
     * Свободный ключ: сам `$base` или `$base-2`, `$base-3`…; отмечается занятым.
     *
     * @param  array<string, true>  $taken
     */
    private static function free(string $base, array &$taken): string
    {
        $key = $base;

        for ($number = 2; isset($taken[$key]); $number++) {
            $key = $base.'-'.$number;
        }

        $taken[$key] = true;

        return $key;
    }

    /**
     * Виджеты, видимые администратору, в порядке показа с размерами и признаком «скрыт».
     *
     * @return list<array{widget: Widget, key: string, cols: int, rows: int, hidden: bool}>
     */
    public static function build(Dashboard $dashboard, ?Authenticatable $user): array
    {
        $visible = array_filter(self::keyed($dashboard), fn (Widget $widget): bool => $widget->isVisible());
        $saved = self::saved($user);
        $items = [];

        foreach ($saved as $entry) {
            if (isset($visible[$entry['key']]) && ! isset($items[$entry['key']])) {
                $items[$entry['key']] = self::item($visible[$entry['key']], $entry['key'], $entry);
            }
        }

        foreach ($visible as $key => $widget) {
            $items[$key] ??= self::item($widget, $key, null);
        }

        return array_values($items);
    }

    /**
     * Сохранённая раскладка администратора (пусто, если не настраивал или таблицы ещё нет).
     *
     * @return list<array{key: string, cols: int, rows: int, hidden: bool}>
     */
    public static function saved(?Authenticatable $user): array
    {
        if (! $user instanceof Authenticatable) {
            return [];
        }

        $id = $user->getAuthIdentifier();

        $query = DashboardLayout::query()->where('user_id', is_scalar($id) ? (string) $id : '');

        try {
            // Своя транзакция: на PostgreSQL ошибка запроса внутри общей транзакции ломает все следующие запросы,
            // а так откатывается только до точки сохранения.
            $layout = $query->getConnection()->transaction(fn (): ?DashboardLayout => $query->first());
        } catch (QueryException) {
            // Таблицы ещё нет (не выполнены миграции) — раскладка из кода, дашборд работает.
            return [];
        }

        return $layout instanceof DashboardLayout ? $layout->layout : [];
    }

    /**
     * Элемент раскладки: размеры из настроек администратора или из кода.
     *
     * @param  array{key: string, cols: int, rows: int, hidden: bool}|null  $entry
     * @return array{widget: Widget, key: string, cols: int, rows: int, hidden: bool}
     */
    private static function item(Widget $widget, string $key, ?array $entry): array
    {
        return [
            'widget' => $widget,
            'key' => $key,
            'cols' => $entry === null ? min(4, $widget->getSpan()) : max(1, min(4, $entry['cols'])),
            'rows' => $entry === null ? $widget->getRows() : max(1, min(4, $entry['rows'])),
            'hidden' => $entry !== null && $entry['hidden'],
        ];
    }
}
