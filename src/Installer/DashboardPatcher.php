<?php

declare(strict_types=1);

namespace Fastleo\Installer;

use Fastleo\Support\UserModelPatcher;
use Illuminate\Support\Facades\File;

/**
 * Дописывает виджеты модулей в `widgets()` класса `Dashboard` сайта. Если класса нет, создаёт его из заготовки
 * (`fastleo:make-dashboard`). Если класс устроен необычно и конец списка не найден, ничего не меняет.
 *
 * ```php
 * new DashboardPatcher()->add(["\Fastleo\Widgets\Table::make('Новости')->resource(\{{ namespace }}\NewsResource::class),"]);
 * ```
 */
final class DashboardPatcher
{
    /**
     * Добавить виджеты, которых ещё нет. Возвращает false, если список виджетов не найден и дописать их нельзя.
     *
     * @param  list<string>  $widgets  строки вида `\Fastleo\Widgets\Stat::make('…'),` ({{ namespace }} — пространство имён разделов сайта, {{ appNamespace }} — приложения)
     */
    public function add(array $widgets): bool
    {
        $namespace = trim(config()->string('fastleo.resources.namespace'), '\\');
        $replace = ['{{ namespace }}' => $namespace, '{{ appNamespace }}' => trim(app()->getNamespace(), '\\')];
        $path = $this->path();

        if (! File::exists($path)) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, strtr(File::get(dirname(__DIR__, 2).'/stubs/dashboard.stub'), [...$replace, '{{ class }}' => 'Dashboard']));
        }

        $source = File::get($path);
        $lines = [];

        foreach ($widgets as $widget) {
            $widget = strtr($widget, $replace);

            if (! str_contains($source, $widget)) {
                $lines[] = $widget;
            }
        }

        if ($lines === []) {
            return true;
        }

        // Список виджетов — `return [ … ];` в конце `widgets()`; после правки файл обязан остаться рабочим PHP.
        $patched = preg_replace_callback(
            '~(?<head>function widgets\(\)[^{]*\{\s*return )(?<body>\[(?:.*?\S)?)\s*\n(?<indent>[ \t]*)\];\n(?<tail>[ \t]*\}\n\}\s*)$~s',
            fn (array $match): string => $match['head'].$match['body'].(in_array(substr($match['body'], -1), [',', '['], true) ? '' : ',')."\n"
                .implode('', array_map(fn (string $line): string => $match['indent'].'    '.$line."\n", $lines)).$match['indent']."];\n".$match['tail'],
            $source,
            1,
            $count,
        );

        if ($count === 0 || $patched === null || ! UserModelPatcher::compiles($patched)) {
            return false;
        }

        File::put($path, $patched);

        return true;
    }

    /**
     * Файл `Dashboard.php` сайта (в папке разделов).
     */
    public function path(): string
    {
        return rtrim(config()->string('fastleo.resources.path'), '/').'/Dashboard.php';
    }
}
