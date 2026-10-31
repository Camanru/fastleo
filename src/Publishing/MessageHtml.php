<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Str;

/**
 * Markdown → HTML, который понимают Telegram и Max: только `b`, `i`, `s`, `code`, `pre`, `a href` (http/https)
 * и `blockquote` без вложенности. Абзацы и переносы — переводами строк, пункты списка — «• » или «1. »,
 * заголовки — жирным, картинки — их подписью, таблицы — текстом. Весь текст экранируется.
 *
 * ```php
 * MessageHtml::fromMarkdown("**Акция**\n\nСкидка 20% до [пятницы](https://site.ru/sale)");
 * // <b>Акция</b>\n\nСкидка 20% до <a href="https://site.ru/sale">пятницы</a>
 * ```
 */
final class MessageHtml
{
    /** Теги, которые остаются тегами. */
    private const array INLINE = ['strong' => 'b', 'b' => 'b', 'em' => 'i', 'i' => 'i', 'del' => 's', 's' => 's', 'code' => 'code'];

    /**
     * HTML сообщения из Markdown.
     */
    public static function fromMarkdown(string $markdown): string
    {
        $html = Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false, 'max_nesting_level' => 50]);
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>', LIBXML_NONET);
        $body = $document->getElementsByTagName('body')->item(0);

        $text = $body instanceof DOMNode ? self::children($body, false) : '';

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * Содержимое узла.
     */
    private static function children(DOMNode $node, bool $quoted): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            $out .= $child instanceof DOMText || $child instanceof DOMElement ? self::node($child, $quoted) : '';
        }

        return $out;
    }

    /**
     * Один узел. `$quoted` — уже внутри цитаты (вложенных цитат мессенджеры не понимают).
     */
    private static function node(DOMText|DOMElement $node, bool $quoted): string
    {
        if ($node instanceof DOMText) {
            return e($node->wholeText, false);
        }

        $tag = strtolower($node->tagName);

        return match (true) {
            isset(self::INLINE[$tag]) && ($tag !== 'code' || $node->parentNode?->nodeName !== 'pre') => '<'.self::INLINE[$tag].'>'.self::children($node, $quoted).'</'.self::INLINE[$tag].'>',
            $tag === 'pre' => '<pre>'.e(rtrim($node->textContent), false).'</pre>'."\n\n",
            $tag === 'a' => self::link($node, $quoted),
            $tag === 'blockquote' => $quoted ? self::children($node, true) : '<blockquote>'.trim(self::children($node, true)).'</blockquote>'."\n\n",
            $tag === 'p' => self::children($node, $quoted)."\n\n",
            $tag === 'br' => "\n",
            in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) => '<b>'.self::children($node, $quoted).'</b>'."\n\n",
            $tag === 'ul', $tag === 'ol' => self::listItems($node, $tag === 'ol', $quoted)."\n",
            $tag === 'img' => e($node->getAttribute('alt'), false),
            $tag === 'hr' => "\n",
            in_array($tag, ['table', 'thead', 'tbody'], true) => self::elements($node, $quoted),
            $tag === 'tr' => trim(self::cells($node, $quoted))."\n",
            default => self::children($node, $quoted),
        };
    }

    /**
     * Только вложенные элементы, без пробелов между ними (таблица).
     */
    private static function elements(DOMNode $node, bool $quoted): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            $out .= $child instanceof DOMElement ? self::node($child, $quoted) : '';
        }

        return $out;
    }

    /**
     * Ссылка: только http(s), иначе — просто текст.
     */
    private static function link(DOMElement $node, bool $quoted): string
    {
        $href = $node->getAttribute('href');
        $text = self::children($node, $quoted);

        return preg_match('#^https?://#i', $href) === 1 ? '<a href="'.e($href, false).'">'.$text.'</a>' : $text;
    }

    /**
     * Пункты списка с маркером.
     */
    private static function listItems(DOMElement $list, bool $ordered, bool $quoted): string
    {
        $out = '';
        $number = 1;

        foreach ($list->childNodes as $item) {
            if ($item instanceof DOMElement && strtolower($item->tagName) === 'li') {
                $out .= ($ordered ? $number++.'. ' : '• ').trim(self::children($item, $quoted))."\n";
            }
        }

        return $out;
    }

    /**
     * Ячейки строки таблицы через « | ».
     */
    private static function cells(DOMElement $row, bool $quoted): string
    {
        $cells = [];

        foreach ($row->childNodes as $cell) {
            if ($cell instanceof DOMElement) {
                $cells[] = trim(self::children($cell, $quoted));
            }
        }

        return implode(' | ', $cells);
    }
}
