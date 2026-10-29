<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Override;

/**
 * Цвет в HEX (`#1e90ff`). Колонка `string`, длина 9.
 *
 * Вводится текстом или палитрой браузера; в списке — образец цвета.
 *
 * ```php
 * Color::make('color', 'Цвет метки')->default('#4f46e5');
 * ```
 */
class Color extends Field
{
    /** Допустимое значение: #rgb, #rgba, #rrggbb, #rrggbbaa. */
    public const string PATTERN = '/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/iD';

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function typeRules(): array
    {
        return ['hex_color'];
    }

    /**
     * Образец и код цвета. Значение из базы проверяется ещё раз: в style попадает только HEX.
     */
    #[Override]
    protected function format(mixed $value): string|Htmlable|null
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            return parent::format($value);
        }

        return new HtmlString('<span class="inline-flex items-center gap-2"><span class="size-4 rounded border border-line-strong" style="background-color: '.$value.'"></span>'.e($value).'</span>');
    }
}
