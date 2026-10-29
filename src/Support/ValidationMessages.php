<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Fastleo;
use Illuminate\Support\Facades\Lang;

/**
 * Русские тексты ошибок валидации для форм админки.
 *
 * В сайте может не быть `lang/ru/validation.php` — тогда ошибки вышли бы по-английски или ключами.
 * Пакет подставляет свою строку только для правил, которых нет у сайта: тексты сайта главнее.
 */
final class ValidationMessages
{
    /**
     * Тексты для `$request->validate(..., messages: ...)`: правило → текст; у правил размера
     * (`max`, `min`, `between`…) — массив по типу значения (`string`, `numeric`, `array`, `file`), как ждёт Laravel.
     *
     * ```php
     * $request->validate($rules, ValidationMessages::all());
     * ```
     *
     * @return array<string, string|array<string, string>>
     */
    public static function all(): array
    {
        /** @var array<string, string|array<string, string>> $lines */
        $lines = require dirname(__DIR__, 2).'/resources/lang/ru/validation.php';

        $messages = [];

        foreach ($lines as $rule => $line) {
            if (is_string($line)) {
                if (! Lang::hasForLocale('validation.'.$rule, Fastleo::LOCALE)) {
                    $messages[$rule] = $line;
                }

                continue;
            }

            $missing = array_filter($line, fn (string $type): bool => ! Lang::hasForLocale('validation.'.$rule.'.'.$type, Fastleo::LOCALE), ARRAY_FILTER_USE_KEY);

            if ($missing !== []) {
                $messages[$rule] = $missing;
            }
        }

        return $messages;
    }

    /**
     * Текст одного правила (без вариантов по типу): строка сайта из `lang/ru/validation.php`, если она есть, иначе пакета.
     * Для своих правил-замыканий, которым Laravel не подставляет сообщения по имени правила.
     *
     * ```php
     * $fail(ValidationMessages::line('exists'));
     * ```
     */
    public static function line(string $rule): string
    {
        if (Lang::hasForLocale('validation.'.$rule, Fastleo::LOCALE)) {
            return (string) __('validation.'.$rule, [], Fastleo::LOCALE);
        }

        /** @var array<string, string|array<string, string>> $lines */
        $lines = require dirname(__DIR__, 2).'/resources/lang/ru/validation.php';
        $line = $lines[$rule] ?? null;

        return is_string($line) ? $line : $rule;
    }
}
