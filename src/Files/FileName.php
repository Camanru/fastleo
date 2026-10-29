<?php

declare(strict_types=1);

namespace Fastleo\Files;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Безопасные имена файлов и папок.
 *
 * Кириллица переводится в латиницу, остаётся только `[a-z0-9-]`; точки внутри имени
 * заменяются (`shell.php.txt` → `shell-php.txt`), чтобы сервер с `AddHandler` не принял
 * файл за скрипт. Существующий файл не перезаписывается: `photo.jpg` → `photo-1.jpg`.
 */
final class FileName
{
    /** Наибольшая длина имени без расширения. */
    private const int MAX_LENGTH = 100;

    /**
     * Имя файла для сохранения.
     *
     * ```php
     * FileName::sanitize('Фото врача.JPG'); // 'foto-vracha.jpg'
     * ```
     */
    public static function sanitize(string $original): string
    {
        $extension = (string) preg_replace('/[^a-z0-9]+/', '', strtolower(pathinfo($original, PATHINFO_EXTENSION)));
        $name = self::slug(pathinfo($original, PATHINFO_FILENAME));

        return $extension === '' ? $name : $name.'.'.$extension;
    }

    /**
     * Имя папки: `Новости 2026` → `novosti-2026`; пустое, если ничего не осталось.
     */
    public static function folder(string $name): string
    {
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', strtolower(Str::ascii($name, 'ru')));

        return mb_substr(trim($slug, '-'), 0, self::MAX_LENGTH);
    }

    /**
     * Свободный путь в папке: `photo.jpg`, затем `photo-1.jpg`, `photo-2.jpg`…
     */
    public static function unique(Filesystem $disk, string $folder, string $name): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $suffix = $extension === '' ? '' : '.'.$extension;
        $path = $folder.'/'.$name;

        for ($number = 1; $disk->exists($path); $number++) {
            $path = $folder.'/'.($number <= 1000 ? $base.'-'.$number : $base.'-'.Str::lower(Str::random(8))).$suffix;
        }

        return $path;
    }

    /**
     * Часть имени без расширения: латиница, цифры и дефис; пустое — случайное.
     */
    private static function slug(string $name): string
    {
        $slug = self::folder($name);

        return $slug === '' ? Str::lower(Str::random(10)) : $slug;
    }
}
