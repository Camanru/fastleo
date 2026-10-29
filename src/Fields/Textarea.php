<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Override;

/**
 * Многострочный текст без оформления. Колонка `text`.
 *
 * В списке скрыт (длинный текст), включается `showInList()` — тогда показывается начало текста.
 *
 * ```php
 * Textarea::make('description', 'Описание')->rows(8);
 * ```
 */
class Textarea extends Field
{
    protected bool $showInList = false;

    /** Высота поля в строках. */
    protected int $rows = 5;

    /** Наибольшая длина или null — без ограничения. */
    protected ?int $maxLength = null;

    /**
     * Высота поля в строках.
     *
     * ```php
     * Textarea::make('description')->rows(10);
     * ```
     */
    public function rows(int $rows): static
    {
        $this->rows = max(1, $rows);

        return $this;
    }

    /**
     * Наибольшая длина текста.
     *
     * ```php
     * Textarea::make('excerpt')->maxLength(500);
     * ```
     */
    public function maxLength(?int $length): static
    {
        $this->maxLength = $length;

        return $this;
    }

    /**
     * Высота поля в строках.
     */
    public function getRows(): int
    {
        return $this->rows;
    }

    /**
     * Наибольшая длина или null.
     */
    public function getMaxLength(): ?int
    {
        return $this->maxLength;
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function typeRules(): array
    {
        return $this->maxLength === null ? ['string'] : ['string', 'max:'.$this->maxLength];
    }

    /**
     * В списке — первые 100 символов; на странице просмотра — полностью.
     */
    #[Override]
    public function listDisplay(Model $record): string|Htmlable|null
    {
        $text = $this->display($record);

        return is_string($text) ? Str::limit($text, 100) : $text;
    }

    #[Override]
    protected function canBeTranslated(): bool
    {
        return true;
    }
}
