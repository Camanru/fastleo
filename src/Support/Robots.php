<?php

declare(strict_types=1);

namespace Fastleo\Support;

/**
 * Текст `robots.txt`: строки из `fastleo.robots.lines` и, если включена карта сайта, ссылка на неё.
 *
 * Адрес админки в файл не попадает: он и так закрыт от индексации заголовком `X-Robots-Tag`, а `Disallow` с ним
 * подсказал бы, где искать вход.
 */
final class Robots
{
    /**
     * ```php
     * Robots::text(); // "User-agent: *\nDisallow:\n\nSitemap: https://site.ru/sitemap.xml\n"
     * ```
     */
    public static function text(): string
    {
        $lines = array_values(array_filter(
            config()->array('fastleo.robots.lines', []),
            static fn (mixed $line): bool => is_string($line) && $line !== '',
        ));

        $text = implode("\n", array_map(static fn (string $line): string => str_replace(["\r", "\n"], '', $line), $lines))."\n";

        if (config()->boolean('fastleo.sitemap.enabled', false)) {
            $text .= "\nSitemap: ".SeoTags::absolute('/sitemap.xml')."\n";
        }

        return $text;
    }
}
