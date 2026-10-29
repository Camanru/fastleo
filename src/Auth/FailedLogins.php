<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use Illuminate\Support\Facades\Cache;

/**
 * Счётчик неудачных входов в админку за последний час (неверный пароль или код второго шага).
 *
 * Хранится в кеше приложения корзинами по 5 минут (12 корзин = час), без адресов и e-mail: нужен только как
 * число для отчёта. Очистка кеша или кеш `array` обнуляют счётчик.
 */
final class FailedLogins
{
    /** Длина корзины, секунд. */
    private const int BUCKET_SECONDS = 300;

    /** Сколько корзин входит в «последний час». */
    private const int BUCKETS = 12;

    /**
     * Засчитать неудачную попытку входа.
     */
    public static function hit(): void
    {
        $key = self::key(self::bucket());

        Cache::add($key, 0, self::BUCKET_SECONDS * (self::BUCKETS + 1));
        Cache::increment($key);
    }

    /**
     * Сколько неудачных попыток было за последний час.
     */
    public static function lastHour(): int
    {
        $current = self::bucket();
        $total = 0;

        for ($offset = 0; $offset < self::BUCKETS; $offset++) {
            $value = Cache::get(self::key($current - $offset));
            $total += is_numeric($value) ? (int) $value : 0;
        }

        return $total;
    }

    /**
     * Номер текущей корзины.
     */
    private static function bucket(): int
    {
        return intdiv(now()->getTimestamp(), self::BUCKET_SECONDS);
    }

    /**
     * Ключ корзины в кеше.
     */
    private static function key(int $bucket): string
    {
        return 'fastleo.failed-logins.'.$bucket;
    }
}
