<?php

declare(strict_types=1);

namespace Fastleo\Installer;

use Illuminate\Support\Facades\File;

/**
 * Правки `routes/web.php` сайта для готовых модулей: подключение `routes/site/*.php`.
 *
 * ```php
 * new WebRoutes()->ensureSiteRoutes();   // один раз дописывает подключение; повторный вызов ничего не меняет
 * ```
 */
final class WebRoutes
{
    /** Пометка подключения: по ней находят, что оно уже добавлено. */
    private const string MARKER = '// fastleo: маршруты готовых модулей';

    /**
     * Дописать в конец подключение `routes/site/*.php`, если его ещё нет. Возвращает, добавлено ли оно сейчас.
     */
    public function ensureSiteRoutes(): bool
    {
        $routes = $this->read();

        if (str_contains($routes, self::MARKER)) {
            return false;
        }

        $routes = $routes === '' ? "<?php\n" : rtrim($routes)."\n";

        File::put($this->path(), $routes."\n".self::MARKER." (routes/site/*.php)\nforeach (glob(__DIR__.'/site/*.php') ?: [] as \$siteRoutes) {\n    require \$siteRoutes;\n}\n");

        return true;
    }

    /**
     * Файл маршрутов сайта.
     */
    private function path(): string
    {
        return base_path('routes/web.php');
    }

    /**
     * Содержимое файла маршрутов, пустая строка — если файла нет.
     */
    private function read(): string
    {
        return File::exists($this->path()) ? File::get($this->path()) : '';
    }
}
