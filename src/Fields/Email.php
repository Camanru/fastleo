<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Override;

/**
 * Адрес электронной почты. Колонка `string`.
 *
 * Сам проверяет формат адреса.
 *
 * ```php
 * Email::make('email', 'Email')->required();
 * ```
 */
class Email extends Text
{
    #[Override]
    public function inputType(): string
    {
        return 'email';
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function typeRules(): array
    {
        return [...parent::typeRules(), 'email'];
    }

    #[Override]
    protected function canBeTranslated(): bool
    {
        return false;
    }
}
