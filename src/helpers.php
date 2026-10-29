<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Support\Arr;

if (! function_exists('fastleo_setting')) {
    /**
     * Значение настройки сайта, заданной в админке: `страница.поле`.
     *
     * ```php
     * fastleo_setting('contacts.phone');                       // '+7 900 000-00-00'
     * fastleo_setting('contacts.lead_email', 'info@site.ru');  // запасное значение, если поле не заполнено
     * ```
     */
    function fastleo_setting(string $key, mixed $default = null): mixed
    {
        return Fastleo::setting($key, $default);
    }
}

if (! function_exists('fastleo_old')) {
    /**
     * Прежнее значение поля после ошибки проверки — только скалярное. Подделанный запрос (`title[]=x`) кладёт в
     * `old()` массив, и шаблон простого поля упал бы с ошибкой 500; вместо него берётся значение по умолчанию.
     *
     * ```blade
     * <input value="{{ fastleo_old($path, $value) }}">
     * ```
     */
    function fastleo_old(string $key, mixed $default = null): mixed
    {
        $request = request();
        $input = $request->hasSession() ? $request->session()->getOldInput() : null;

        if (! is_array($input) || ! Arr::has($input, $key)) {
            return $default;
        }

        $old = Arr::get($input, $key);

        return is_scalar($old) || $old === null ? $old : $default;
    }
}
