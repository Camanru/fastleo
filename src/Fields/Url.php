<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Override;

/**
 * Адрес страницы. Колонка `string`.
 *
 * Сам проверяет: адрес начинается с http:// или https:// (никаких `javascript:`).
 * В списке выводится ссылкой, открывается в новой вкладке.
 *
 * ```php
 * Url::make('website', 'Сайт');
 * ```
 */
class Url extends Text
{
    #[Override]
    public function inputType(): string
    {
        return 'url';
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function typeRules(): array
    {
        return [...parent::typeRules(), 'url:http,https'];
    }

    /**
     * Ссылка, если в базе действительно http(s)-адрес, иначе просто текст.
     */
    #[Override]
    protected function format(mixed $value): string|Htmlable|null
    {
        $text = parent::format($value);

        if (! is_string($text) || preg_match('#^https?://#i', $text) !== 1) {
            return $text;
        }

        return new HtmlString('<a href="'.e($text).'" target="_blank" rel="noopener noreferrer" class="text-primary-text hover:underline">'.e($text).'</a>');
    }

    #[Override]
    protected function canBeTranslated(): bool
    {
        return false;
    }
}
