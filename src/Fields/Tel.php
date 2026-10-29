<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Override;

/**
 * Телефон. Колонка `string`. На телефоне открывает цифровую клавиатуру.
 *
 * Хранится как введён; длина — до 32 символов.
 *
 * ```php
 * Tel::make('phone', 'Телефон')->placeholder('+7 900 000-00-00');
 * ```
 */
class Tel extends Text
{
    protected int $maxLength = 32;

    #[Override]
    public function inputType(): string
    {
        return 'tel';
    }

    #[Override]
    protected function canBeTranslated(): bool
    {
        return false;
    }
}
