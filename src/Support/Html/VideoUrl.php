<?php

declare(strict_types=1);

namespace Fastleo\Support\Html;

/**
 * Ссылка на видео → адрес плеера для `<iframe>`: YouTube, Rutube, VK Видео. Любая другая ссылка (чужой сайт,
 * `javascript:`, `youtube.com.evil.ru`) даёт null. Адреса плееров всегда начинаются с одного из `VideoFrameSanitizer::PLAYERS`,
 * поэтому их принимает и чистка HTML поля RichText.
 *
 * ```php
 * VideoUrl::embed('https://youtu.be/dQw4w9WgXcQ'); // 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'
 * VideoUrl::embed('https://example.com/video');    // null
 * ```
 */
final class VideoUrl
{
    /**
     * Адрес плеера по ссылке на видео или null, если ссылка не из поддерживаемых сервисов.
     */
    public static function embed(mixed $url): ?string
    {
        if (! is_string($url) || preg_match('/[\s"\'<>\\\\]/', trim($url)) === 1) {
            return null;
        }

        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ! isset($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);
        $host = strtolower($parts['host']);
        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), fn (string $segment): bool => $segment !== ''));

        return match (true) {
            in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true) => self::youtube(match ($segments[0] ?? '') {
                'watch' => $query['v'] ?? null,
                'embed', 'shorts', 'live' => $segments[1] ?? null,
                default => null,
            }),
            $host === 'youtu.be' => self::youtube($segments[0] ?? null),
            in_array($host, ['rutube.ru', 'www.rutube.ru'], true) => self::rutube(match ($segments[0] ?? '') {
                'video' => $segments[1] ?? null,
                'play' => ($segments[1] ?? '') === 'embed' ? $segments[2] ?? null : null,
                default => null,
            }),
            in_array($host, ['vk.com', 'www.vk.com', 'vkvideo.ru', 'www.vkvideo.ru'], true) => self::vk($segments[0] ?? '', $query),
            default => null,
        };
    }

    /**
     * Плеер YouTube без cookie по коду ролика из 11 знаков.
     */
    private static function youtube(mixed $id): ?string
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}\z/', $id) === 1 ? 'https://www.youtube-nocookie.com/embed/'.$id : null;
    }

    /**
     * Плеер Rutube по коду из 32 шестнадцатеричных знаков.
     */
    private static function rutube(mixed $id): ?string
    {
        return is_string($id) && preg_match('/^[0-9a-f]{32}\z/', $id) === 1 ? 'https://rutube.ru/play/embed/'.$id : null;
    }

    /**
     * Плеер VK Видео: страница ролика `video-22822305_456239026` или готовый адрес плеера `video_ext.php?oid=…&id=…&hash=…`.
     *
     * @param  array<array-key, mixed>  $query
     */
    private static function vk(string $first, array $query): ?string
    {
        if ($first === 'video_ext.php') {
            $oid = $query['oid'] ?? null;
            $id = $query['id'] ?? null;
            $hash = $query['hash'] ?? null;
        } elseif (preg_match('/^video(-?\d+)_(\d+)\z/', $first, $found) === 1) {
            [, $oid, $id] = $found;
            $hash = null;
        } else {
            return null;
        }

        if (! is_string($oid) || ! is_string($id) || preg_match('/^-?\d+\z/', $oid) !== 1 || preg_match('/^\d+\z/', $id) !== 1 || ($hash !== null && (! is_string($hash) || preg_match('/^[0-9a-f]+\z/', $hash) !== 1))) {
            return null;
        }

        return 'https://vk.com/video_ext.php?oid='.$oid.'&id='.$id.($hash === null ? '' : '&hash='.$hash).'&hd=2';
    }
}
