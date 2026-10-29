<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Override;

/**
 * Однострочный текст. Колонка `string` (varchar).
 *
 * Сам проверяет: строка не длиннее 255 символов (меняется `maxLength()`).
 *
 * ```php
 * Text::make('title', 'Заголовок')->required()->searchable()->sortable();
 * ```
 */
class Text extends Field
{
    /** Наибольшая длина строки. */
    protected int $maxLength = 255;

    /**
     * Наибольшая длина строки (по умолчанию 255 — как у `$table->string()`).
     *
     * ```php
     * Text::make('code')->maxLength(20);
     * ```
     */
    public function maxLength(int $length): static
    {
        $this->maxLength = $length;

        return $this;
    }

    /**
     * Наибольшая длина строки.
     */
    public function getMaxLength(): int
    {
        return $this->maxLength;
    }

    /**
     * Тип `<input>` в форме.
     */
    public function inputType(): string
    {
        return 'text';
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function typeRules(): array
    {
        return ['string', 'max:'.$this->maxLength];
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::fields.text';
    }

    #[Override]
    protected function canBeTranslated(): bool
    {
        return true;
    }
}
