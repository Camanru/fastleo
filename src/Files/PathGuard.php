<?php

declare(strict_types=1);

namespace Fastleo\Files;

use Fastleo\Fastleo;
use LogicException;

/**
 * Проверка путей файлового менеджера: всё, что пришло из запроса, остаётся внутри
 * корня загрузок (`fastleo.files.root`).
 *
 * Проверяется исходная строка, по сегментам, до любой нормализации: Flysystem сам
 * схлопывает `uploads/.` и `uploads//`, и такой путь указал бы на весь корень.
 * Отклоняются: не строки, `\`, нулевой байт и управляющие символы, пустые сегменты,
 * `.` и `..`, скрытые файлы (`.env`, `.htaccess`), пути вне корня.
 */
final class PathGuard
{
    /** Наибольшая длина пути. */
    private const int MAX_LENGTH = 1024;

    /**
     * Корень загрузок на диске, например `uploads`.
     *
     * @throws LogicException корень в конфиге пустой или с недопустимыми символами
     */
    public static function root(): string
    {
        $root = trim(config()->string('fastleo.files.root', 'uploads'), '/');

        if ($root === '' || ! self::validSegments($root) || $root === 'fastleo' || str_starts_with($root, 'fastleo/')) {
            throw new LogicException(__('fastleo::exceptions.files_root', ['root' => $root], Fastleo::LOCALE));
        }

        return $root;
    }

    /**
     * Путь внутри корня (сам корень тоже подходит) или null.
     *
     * ```php
     * PathGuard::inside('uploads/doctors');   // 'uploads/doctors'
     * PathGuard::inside('uploads/../.env');   // null
     * ```
     */
    public static function inside(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || strlen($path) > self::MAX_LENGTH || ! self::validSegments($path)) {
            return null;
        }

        $root = self::root();

        return $path === $root || str_starts_with($path, $root.'/') ? $path : null;
    }

    /**
     * Путь к файлу или папке внутри корня, но не сам корень.
     */
    public static function entry(mixed $path): ?string
    {
        $path = self::inside($path);

        return $path === null || $path === self::root() ? null : $path;
    }

    /**
     * Все сегменты непустые, без `.`/`..`, не скрытые, без `\` и управляющих символов.
     */
    private static function validSegments(string $path): bool
    {
        if (str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }

        return array_all(explode('/', $path), fn (string $segment): bool => $segment !== '' && ! str_starts_with($segment, '.'));
    }
}
