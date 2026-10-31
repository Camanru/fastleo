<?php

declare(strict_types=1);

namespace Fastleo\Support\Html;

use Dom\HTMLDocument;

/**
 * Что пропадёт из HTML при чистке: теги, атрибуты и адреса плееров iframe, которых после неё стало меньше.
 * Нужен `fastleo:doctor`, чтобы сайт узнал о потере разметки до первого сохранения старой записи.
 *
 * ```php
 * HtmlLoss::between('<div class="a" data-x="1">текст</div>', 'текст'); // ['tags' => ['div'], 'attributes' => ['class', 'data-x'], 'hosts' => []]
 * ```
 */
final class HtmlLoss
{
    /**
     * Потери между исходным и очищенным HTML.
     *
     * @return array{tags: list<string>, attributes: list<string>, hosts: list<string>}
     */
    public static function between(string $original, ?string $clean): array
    {
        $before = self::inventory($original);
        $after = self::inventory($clean ?? '');

        return [
            'tags' => self::fewer($before['tags'], $after['tags']),
            'attributes' => self::fewer($before['attributes'], $after['attributes']),
            'hosts' => array_values(array_diff(array_keys($before['hosts']), array_keys($after['hosts']))),
        ];
    }

    /**
     * Имена, которых в исходном больше, чем в очищенном.
     *
     * @param  array<string, int>  $before
     * @param  array<string, int>  $after
     * @return list<string>
     */
    private static function fewer(array $before, array $after): array
    {
        $lost = [];

        foreach ($before as $name => $count) {
            if ($count > ($after[$name] ?? 0)) {
                $lost[] = $name;
            }
        }

        return $lost;
    }

    /**
     * Сколько раз встречается каждый тег и атрибут и какие хосты у iframe.
     *
     * @return array{tags: array<string, int>, attributes: array<string, int>, hosts: array<string, true>}
     */
    private static function inventory(string $html): array
    {
        $result = ['tags' => [], 'attributes' => [], 'hosts' => []];
        $body = HTMLDocument::createFromString('<!DOCTYPE html><body>'.$html, LIBXML_NOERROR)->body;

        foreach ($body?->getElementsByTagName('*') ?? [] as $element) {
            $tag = strtolower($element->localName);
            $result['tags'][$tag] = ($result['tags'][$tag] ?? 0) + 1;

            foreach ($element->attributes as $attribute) {
                $result['attributes'][$attribute->name] = ($result['attributes'][$attribute->name] ?? 0) + 1;
            }

            if ($tag === 'iframe') {
                $host = parse_url((string) $element->getAttribute('src'), PHP_URL_HOST);
                $result['hosts'][is_string($host) && $host !== '' ? $host : 'iframe'] = true;
            }
        }

        return $result;
    }
}
