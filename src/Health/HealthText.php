<?php

declare(strict_types=1);

namespace Fastleo\Health;

/**
 * Очистка текста проверки перед отправкой в SoftNetica: без управляющих символов, ссылок, адресов электронной
 * почты, IP-адресов, путей к файлам и длинных «похожих на секрет» строк. Панель делает то же, но на неё
 * не полагаются.
 */
final class HealthText
{
    /**
     * Очистить текст и обрезать до `$limit` символов.
     */
    public static function clean(string $text, int $limit): string
    {
        $base = base_path();

        if ($base !== '' && $base !== DIRECTORY_SEPARATOR) {
            $text = str_replace($base, '', $text);
        }

        $text = (string) preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $text);
        $text = (string) preg_replace('~(?:[a-z][a-z0-9+.-]*://|\bwww\.)\S+~iu', '[ссылка]', $text);
        $text = (string) preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', '[скрыто]', $text);
        $text = (string) preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[скрыто]', $text);
        // Сокращённый IPv6: fe80::1, 2001:db8::1, ::1.
        $text = (string) preg_replace('/(?<![\w:])(?:(?:[0-9a-f]{1,4}:){1,7}:(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?|::[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})(?![\w:])/i', '[скрыто]', $text);
        $text = (string) preg_replace('/\b(?:[0-9a-f]{1,4}:){2,7}[0-9a-f]{1,4}\b/i', '[скрыто]', $text);
        $text = (string) preg_replace('~(?<![\w:/])/(?:[\w.-]+/)+[\w.-]*~', '[путь]', $text);
        $text = (string) preg_replace('~\b[A-Za-z]:\\\\[^\s]*~', '[путь]', $text);
        $text = (string) preg_replace('/\b[A-Za-z0-9+_-]{32,}\b/', '[скрыто]', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)).'…' : $text;
    }
}
