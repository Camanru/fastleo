<?php

declare(strict_types=1);

namespace Fastleo\Support;

/**
 * Адреса собранных CSS/JS пакета.
 *
 * Файлы публикуются в public/vendor/fastleo (`php artisan vendor:publish --tag=fastleo-assets`,
 * а также под тегом laravel-assets, который сайт на Laravel обновляет при `composer update`).
 * К адресу добавляется время изменения файла, чтобы браузер не держал старую версию.
 */
final class Assets
{
    /** Папка публикации внутри public. */
    public const string PUBLIC_PATH = 'vendor/fastleo';

    /**
     * Адрес файла из resources/dist с меткой версии.
     *
     * ```php
     * Assets::url('fastleo.css'); // https://site.ru/vendor/fastleo/fastleo.css?v=1761660000
     * ```
     */
    public static function url(string $file): string
    {
        $published = public_path(self::PUBLIC_PATH.'/'.$file);
        $source = is_file($published) ? $published : self::distPath($file);
        $version = is_file($source) ? (string) filemtime($source) : '0';

        return asset(self::PUBLIC_PATH.'/'.$file).'?v='.$version;
    }

    /**
     * Ассеты опубликованы в public (`vendor:publish --tag=fastleo-assets`): без них страницы открываются без стилей.
     */
    public static function published(): bool
    {
        return is_file(public_path(self::PUBLIC_PATH.'/fastleo.css'));
    }

    /**
     * Путь к собранному файлу внутри пакета.
     */
    public static function distPath(string $file = ''): string
    {
        return dirname(__DIR__, 2).'/resources/dist'.($file === '' ? '' : '/'.$file);
    }
}
