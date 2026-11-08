<?php

declare(strict_types=1);

namespace Fastleo\Support\Html;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Атрибут `style` остаётся, но без опасного: адресов (`url()`), `expression`, `javascript:`, `@import`, `behavior`,
 * экранирования `\`, комментариев и всего, чем текст ложится поверх страницы (невидимая ссылка на весь экран ловит клики
 * владельца): `position` кроме `static`, `z-index`, `transform` и родственные (в том числе с префиксом браузера),
 * движение по траектории (`offset-*`), а отступы и смещения — только простыми длинами: ни отрицательных чисел,
 * ни функций (`calc()`, `var()`), которыми тот же сдвиг записывается иначе.
 * Включается настройкой `fastleo.rich_text.styles`; без неё остаётся только `text-align` (TextAlignSanitizer).
 */
final class SafeStyleSanitizer implements AttributeSanitizerInterface
{
    /** Свойства, которыми блок выводится поверх соседей: порядок наложения и сдвиг. */
    private const array OVERLAY = ['z-index', 'transform', 'transform-origin', 'translate', 'rotate', 'scale', 'perspective'];

    /**
     * Одно значение отступа или смещения: ноль, `auto` или неотрицательная длина в обычных единицах (число может
     * начинаться с точки, как пишет Word: `.5em`, `.0001pt`).
     * Всё остальное (минус, `calc()`, `var()`, `!important`) отрезается: отрицательный сдвиг можно записать многими способами.
     */
    private const string PLAIN_LENGTH = '(?:auto|0|(?:\d+(?:\.\d+)?|\.\d+)(?:px|em|rem|%|ch|ex|cap|ic|lh|rlh|pt|pc|cm|mm|Q|in|[sld]?v[whib]|vmin|vmax|[sld]vmin|[sld]vmax|cq[whib]|cqmin|cqmax)?)';

    public function getSupportedElements(): ?array
    {
        return null;
    }

    /**
     * @return list<string>
     */
    public function getSupportedAttributes(): array
    {
        return ['style'];
    }

    /**
     * Безопасные объявления через `; ` или null, если не осталось ни одного.
     */
    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $kept = [];

        foreach (explode(';', $value) as $declaration) {
            if (preg_match('/^\s*([a-zA-Z-]+)\s*:\s*(.+?)\s*$/s', $declaration, $match) !== 1) {
                continue;
            }

            [, $property, $content] = $match;

            if (preg_match('/url\s*\(|image-set|src\s*\(|expression|javascript|vbscript|behavior|binding|@|\\\\|\/\*|[<>]/i', $content) === 1) {
                continue;
            }

            $property = strtolower($property);

            if ($this->overlaps($property, $content)) {
                continue;
            }

            $kept[] = $property.': '.$content;
        }

        return $kept === [] ? null : implode('; ', $kept);
    }

    /**
     * Объявление выводит блок поверх соседей: любое позиционирование, кроме `static`, порядок наложения, преобразования
     * и движение по траектории (в том числе с префиксом браузера: `-webkit-transform`), а у отступов и смещений любое
     * значение, кроме простых длин (даже без `position` блок с `margin: -50vh` ложится на предыдущий текст).
     */
    private function overlaps(string $property, string $content): bool
    {
        $property = (string) preg_replace('/^-(webkit|moz|ms|o)-/', '', $property);

        if ($property === 'position') {
            return strtolower(trim($content)) !== 'static';
        }

        if (in_array($property, self::OVERLAY, true) || str_starts_with($property, 'offset')) {
            return true;
        }

        return preg_match('/^(margin|inset|top|right|bottom|left)(-|$)/', $property) === 1
            && preg_match('/^'.self::PLAIN_LENGTH.'(?:\s+'.self::PLAIN_LENGTH.'){0,3}$/i', $content) !== 1;
    }
}
