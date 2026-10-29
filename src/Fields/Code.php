<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Override;

/**
 * Код или HTML-вставка (счётчик, виджет): моноширинный шрифт, Tab вставляет отступ (Esc, затем Tab — выйти из поля).
 * Значение хранится без чистки HTML (пробелы по краям всего текста обрезаются, отступы строк остаются). В админке всегда показывается текстом;
 * выводить его как HTML на сайте — решение сайта (`{!! $page->head_code !!}`).
 *
 * ```php
 * Code::make('head_code', 'Код в <head>')->rows(10);
 * ```
 */
class Code extends Textarea
{
    /** Высота поля в строках. */
    protected int $rows = 10;

    /**
     * На просмотре — моноширинным блоком, экранированно.
     */
    #[Override]
    public function display(Model $record): string|Htmlable|null
    {
        $value = parent::display($record);

        if (! is_string($value)) {
            return $value;
        }

        return new HtmlString('<pre class="overflow-x-auto font-mono text-xs whitespace-pre">'.e($value).'</pre>');
    }

    /**
     * В списке (если включён `showInList()`) — первые 100 символов текстом.
     */
    #[Override]
    public function listDisplay(Model $record): string|Htmlable|null
    {
        $value = $record->getAttribute($this->name);

        return is_string($value) && $value !== '' ? Str::limit($value, 100) : null;
    }
}
