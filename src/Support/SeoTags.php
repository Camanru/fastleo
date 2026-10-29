<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Files\FileStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Значения мета-тегов страницы для `<x-fastleo::seo>`: поле Seo записи → переданные значения → название сайта.
 *
 * Адреса canonical и картинки строятся от `APP_URL`, а не от заголовка Host запроса: подменённый Host
 * не попадёт в поисковую выдачу. Описание из HTML (текст редактора) очищается от тегов и обрезается по слову.
 *
 * ```php
 * SeoTags::resolve($article, title: $article->title, description: $article->body);
 * ```
 */
final class SeoTags
{
    /** Длина описания, полученного из текста, в символах. */
    public const int DESCRIPTION_LENGTH = 160;

    /**
     * Итоговые значения тегов.
     *
     * @param  string|null  $field  имя колонки с полем Seo (по умолчанию `seo`)
     * @return array{title: ?string, description: ?string, image: ?string, canonical: string, noindex: bool, type: string, site: string}
     */
    public static function resolve(?Model $model = null, ?string $field = null, ?string $title = null, ?string $description = null, ?string $image = null, ?string $canonical = null, string $type = 'website'): array
    {
        $seo = self::values($model, $field ?? 'seo');

        return [
            'title' => self::first(self::text($seo['title'] ?? null), $title, config()->string('app.name', '')),
            'description' => self::first(self::text($seo['description'] ?? null), self::excerpt($description)),
            'image' => self::imageUrl(self::first(self::text($seo['image'] ?? null), $image)),
            'canonical' => self::canonical($canonical),
            'noindex' => ($seo['noindex'] ?? false) === true,
            'type' => preg_match('/^[a-z]+(?:\.[a-z_]+)?$/', $type) === 1 ? $type : 'website',
            'site' => config()->string('app.name', ''),
        ];
    }

    /**
     * Описание из HTML: текст абзацев без заголовков, скриптов и стилей, сущности символами,
     * не длиннее 160 знаков с обрезкой по границе слова.
     */
    public static function excerpt(?string $html): ?string
    {
        $withoutHeadings = (string) preg_replace('#<(h[1-6]|script|style|noscript|template)\b[^>]*>.*?</\1\s*>#isu', ' ', (string) $html);
        $text = Str::squish(html_entity_decode(strip_tags(str_replace('<', ' <', $withoutHeadings)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : Str::limit($text, self::DESCRIPTION_LENGTH, '…', preserveWords: true);
    }

    /**
     * Абсолютный адрес на основном домене (`APP_URL`) без параметров запроса и слэша на конце.
     * Путь с протоколом (`https://…`) возвращается как есть.
     */
    public static function absolute(string $path): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return rtrim(config()->string('app.url', ''), '/').'/'.ltrim($path, '/');
    }

    /**
     * Значения поля Seo записи.
     *
     * @return array<array-key, mixed>
     */
    private static function values(?Model $model, string $field): array
    {
        $value = $model?->getAttribute($field);

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    /**
     * Строка или null: пустые и не строки отбрасываются.
     */
    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Первое непустое значение.
     */
    private static function first(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Адрес картинки: путь на диске → публичный адрес; допускаются только http(s) и адреса с `/`.
     */
    private static function imageUrl(?string $image): ?string
    {
        if ($image === null) {
            return null;
        }

        if (preg_match('#^https?://#i', $image) === 1) {
            return $image;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:|^//#i', $image) === 1) {
            return null;
        }

        return self::absolute(FileStore::url($image));
    }

    /**
     * Canonical: заданный адрес или текущий путь запроса на `APP_URL` без параметров.
     */
    private static function canonical(?string $canonical): string
    {
        $canonical = self::text($canonical);

        if ($canonical !== null && preg_match('#^(https?://|/(?!/))#i', $canonical) === 1) {
            return self::absolute($canonical);
        }

        return self::absolute(request()->path() === '/' ? '' : request()->path());
    }
}
