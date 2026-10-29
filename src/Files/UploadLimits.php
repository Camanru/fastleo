<?php

declare(strict_types=1);

namespace Fastleo\Files;

use Fastleo\Fastleo;

/**
 * Лимиты загрузки: PHP (`upload_max_filesize`, `post_max_size`) и `fastleo.files.max_size`.
 *
 * Окно загрузки показывает их и не отправляет слишком большие файлы: иначе PHP молча
 * отбросит запрос целиком и админ не поймёт, что случилось.
 */
final class UploadLimits
{
    /**
     * Наибольший размер одного файла в байтах (0 — без ограничения).
     */
    public static function perFile(): int
    {
        $limits = array_filter([
            self::bytes((string) ini_get('upload_max_filesize')),
            self::total(),
            self::configured(),
        ]);

        return $limits === [] ? 0 : min($limits);
    }

    /**
     * Наибольший размер всех файлов одной загрузки в байтах (0 — без ограничения).
     */
    public static function total(): int
    {
        return self::bytes((string) ini_get('post_max_size'));
    }

    /**
     * Наибольший размер файла в КБ для правила `max` (null — без ограничения).
     */
    public static function kilobytes(): ?int
    {
        $bytes = self::perFile();

        return $bytes === 0 ? null : intdiv($bytes, 1024);
    }

    /**
     * Значение php.ini в байтах: `2M` → 2097152, `512k` → 524288, `1G`, число как есть.
     *
     * ```php
     * UploadLimits::bytes('8M'); // 8388608
     * ```
     */
    public static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || ! is_numeric(rtrim($value, 'kKmMgG'))) {
            return 0;
        }

        $number = max(0, (int) $value);

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Размер для людей: 2097152 → «2 МБ», 1536 → «1,5 КБ».
     */
    public static function human(int $bytes): string
    {
        $units = ['bytes', 'kb', 'mb', 'gb'];
        $power = $bytes > 0 ? min(3, (int) floor(log($bytes, 1024))) : 0;
        $value = round($bytes / 1024 ** $power, $power === 0 ? 0 : 1);

        return __('fastleo::files.size_'.$units[$power], ['size' => str_replace('.', ',', (string) $value)], Fastleo::LOCALE);
    }

    /**
     * Лимит из конфига в байтах (0 — не задан).
     */
    private static function configured(): int
    {
        $kilobytes = config('fastleo.files.max_size');

        return is_int($kilobytes) && $kilobytes > 0 ? $kilobytes * 1024 : 0;
    }
}
