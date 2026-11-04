<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Fastleo;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Stringable;

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
     * Laravel подбирает сообщение по ключам `поле.правило`, `правило` и `поле`, поэтому ключ `url` перехватил бы
     * текст любого правила-объекта на поле с именем `url`. Передайте правила формы — ключи, совпавшие с именем
     * поля, заменятся на `поле.правило` и выдадутся только полям, которые это правило используют.
     *
     * ```php
     * $request->validate($rules, ValidationMessages::all($rules));
     * ```
     *
     * @param  array<string, mixed>  $rules  правила формы: поле → правила
     * @return array<string, string|array<string, string>>
     */
    public static function all(array $rules = []): array
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

        return self::scopedToFields($messages, $rules);
    }

    /**
     * Ключи сообщений, совпавшие с именем поля формы, переводит в вид `поле.правило` для полей, где правило указано.
     *
     * @param  array<string, string|array<string, string>>  $messages
     * @param  array<string, mixed>  $rules
     * @return array<string, string|array<string, string>>
     */
    private static function scopedToFields(array $messages, array $rules): array
    {
        $clashing = array_intersect_key($messages, $rules);

        foreach (array_keys($clashing) as $rule) {
            unset($messages[$rule]);
        }

        foreach ($rules as $field => $fieldRules) {
            foreach (is_string($fieldRules) ? explode('|', $fieldRules) : (is_array($fieldRules) ? $fieldRules : [$fieldRules]) as $given) {
                $name = is_string($given) || $given instanceof Stringable ? Str::snake(explode(':', (string) $given, 2)[0]) : null;

                if ($name !== null && isset($clashing[$name])) {
                    $messages[$field.'.'.$name] = $clashing[$name];
                }
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
