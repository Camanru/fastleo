<?php

declare(strict_types=1);

namespace Fastleo\Support\Html;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Атрибут `style` остаётся, но без опасного: адресов (`url()`), `expression`, `javascript:`, `@import`, `behavior`,
 * экранирования `\`, комментариев и позиционирования `fixed`/`sticky` (накладка на страницу). Включается
 * настройкой `fastleo.rich_text.styles`; без неё остаётся только `text-align` (TextAlignSanitizer).
 */
final class SafeStyleSanitizer implements AttributeSanitizerInterface
{
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

            if (preg_match('/url\s*\(|expression|javascript|vbscript|behavior|binding|@|\\\\|\/\*|[<>]/i', $content) === 1) {
                continue;
            }

            if (strtolower($property) === 'position' && preg_match('/fixed|sticky/i', $content) === 1) {
                continue;
            }

            $kept[] = strtolower($property).': '.$content;
        }

        return $kept === [] ? null : implode('; ', $kept);
    }
}
