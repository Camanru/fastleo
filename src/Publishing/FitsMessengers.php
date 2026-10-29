<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Messengers\Bot;
use Fastleo\Messengers\TelegramBot;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Текст поста (Markdown) помещается в сообщение Telegram и Max после перевода в HTML: Telegram считает видимый
 * текст без тегов (до 4096 единиц UTF-16), Max — текст вместе с разметкой (до 4000 символов).
 */
final class FitsMessengers implements ValidationRule
{
    /**
     * Проверить длину.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $html = MessageHtml::fromMarkdown($value);

        if (TelegramBot::visibleLength($html) > TelegramBot::MESSAGE_LIMIT || mb_strlen($html) > Bot::TEXT_LIMIT) {
            $fail(__('fastleo::publishing.text_too_long', ['max' => Bot::TEXT_LIMIT], Fastleo::LOCALE));
        }
    }
}
