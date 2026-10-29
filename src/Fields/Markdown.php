<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Override;

/**
 * Текст в разметке Markdown: вкладки «Текст» и «Просмотр». Хранится исходный Markdown;
 * на сайте его выводят через `Str::markdown()` (или `Markdown::render()` — с теми же настройками безопасности).
 *
 * ```php
 * Markdown::make('body', 'Текст')->rows(15);
 * ```
 */
class Markdown extends Textarea
{
    /** Высота поля в строках. */
    protected int $rows = 12;

    /**
     * Markdown → HTML без опасного: HTML внутри текста экранируется, ссылки `javascript:` убираются. Пустой текст или null — пустая строка.
     *
     * ```php
     * {!! Markdown::render($article->body) !!}
     * ```
     */
    public static function render(?string $markdown): HtmlString
    {
        if ($markdown === null || $markdown === '') {
            return new HtmlString('');
        }

        return new HtmlString(Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]));
    }

    /**
     * На просмотре — готовым HTML.
     */
    #[Override]
    public function display(Model $record): string|Htmlable|null
    {
        $value = $record->getAttribute($this->name);

        if ($this->formatUsing instanceof \Closure || ! is_string($value) || $value === '') {
            return parent::display($record);
        }

        return new HtmlString('<div class="fastleo-prose">'.self::render($value)->toHtml().'</div>');
    }

    /**
     * В списке (если включён `showInList()`) — первые 100 символов исходного текста.
     */
    #[Override]
    public function listDisplay(Model $record): string|Htmlable|null
    {
        $value = $record->getAttribute($this->name);

        return is_string($value) && $value !== '' ? Str::limit($value, 100) : null;
    }
}
