<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Illuminate\Http\Request;

/**
 * Адрес возврата: после сохранения или удаления из связанной таблицы админка возвращает на страницу родителя,
 * а не в список. Принимается только полный адрес страницы админки этого сайта; всё остальное (чужой хост,
 * `//host`, `javascript:`, переводы строк) отбрасывается.
 *
 * ```php
 * $return = ReturnUrl::from($request); // ?return=… в адресе или поле _return в форме
 * ```
 */
final class ReturnUrl
{
    /** Самый длинный принимаемый адрес. */
    private const int MAX_LENGTH = 2000;

    /**
     * Адрес возврата из запроса (`return` в адресе, `_return` в форме) или null.
     */
    public static function from(Request $request): ?string
    {
        return self::sanitize($request->query('return', $request->input('_return')));
    }

    /**
     * Адрес страницы админки или null.
     */
    public static function sanitize(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }

        // Выход из папки админки через `..` (в том числе закодированный) не годится: адрес вёл бы на страницу сайта.
        if (preg_match('/\.\.|%2e/i', strtok($url, '?#')) === 1) {
            return null;
        }

        // Адреса страниц строятся и по адресу сайта в настройках, и по адресу текущего запроса (прокси, www): годятся оба.
        $path = route('fastleo.dashboard', [], false);

        foreach ([route('fastleo.dashboard'), request()->root().$path] as $dashboard) {
            if (str_starts_with($url, $dashboard.'/')) {
                return $url;
            }
        }

        return null;
    }
}
