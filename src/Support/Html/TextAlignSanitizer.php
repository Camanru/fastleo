<?php

declare(strict_types=1);

namespace Fastleo\Support\Html;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Из атрибута `style` остаётся только выравнивание текста: `text-align: center`.
 * Цвета, шрифты, размеры и остальное оформление снимаются — оформление задаёт сайт.
 */
final class TextAlignSanitizer implements AttributeSanitizerInterface
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
     * `text-align: left|center|right|justify` или null.
     */
    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        foreach (explode(';', strtolower($value)) as $declaration) {
            if (preg_match('/^\s*text-align\s*:\s*(left|center|right|justify)\s*$/', $declaration, $match) === 1) {
                return 'text-align: '.$match[1];
            }
        }

        return null;
    }
}
