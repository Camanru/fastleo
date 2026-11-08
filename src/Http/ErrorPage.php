<?php

declare(strict_types=1);

namespace Fastleo\Http;

use Fastleo\Fastleo;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Страницы ошибок внутри админки: 403, 404, 419 и 429 в оформлении админки вместо голой страницы Laravel.
 *
 * Только для адресов админки и обычных страниц (запросы, которые ждут JSON, и ошибки сайта остаются как есть). Страница
 * показывается в меню админки, если администратор вошёл, на экране входа — если нет, в окне — без меню. Свой вид —
 * шаблон `resources/views/vendor/fastleo/errors/page.blade.php`.
 *
 * ```php
 * $handler->renderable(fn (HttpExceptionInterface $e, Request $request) => ErrorPage::render($e, $request));
 * ```
 */
final class ErrorPage
{
    /** Коды ошибок, для которых есть своя страница. */
    public const array STATUSES = [403, 404, 419, 429];

    /** Иконка страницы по коду. */
    private const array ICONS = [403 => 'shield-alert', 404 => 'search-x', 419 => 'clock-alert', 429 => 'gauge'];

    /**
     * Ответ со страницей ошибки или `null`, если ошибка не из админки или для неё нет своей страницы.
     */
    public static function render(HttpExceptionInterface $exception, Request $request): ?Response
    {
        $status = $exception->getStatusCode();

        if (! in_array($status, self::STATUSES, true) || $request->expectsJson() || ! self::isAdminRequest($request)) {
            return null;
        }

        // Ошибка могла случиться раньше, чем сработали защита админки и выбор языка (адрес без маршрута, неверный CSRF-токен).
        Auth::shouldUse(Fastleo::GUARD);
        app()->setLocale(Fastleo::LOCALE);

        $retry = $exception->getHeaders()['Retry-After'] ?? null;
        $retry = is_numeric($retry) ? (int) $retry : null;

        return response()->view('fastleo::errors.page', [
            'status' => $status,
            'icon' => self::ICONS[$status],
            'title' => __('fastleo::errors.'.$status.'.title', [], Fastleo::LOCALE),
            'text' => __('fastleo::errors.'.$status.'.text', [], Fastleo::LOCALE),
            'retry' => $status === 429 && $retry !== null ? __('fastleo::errors.429.retry', ['seconds' => $retry], Fastleo::LOCALE) : null,
            'signedIn' => Fastleo::guard()->check(),
            'framed' => $request->query('frame') !== null || $request->input('_frame') !== null,
        ], $status, $exception->getHeaders());
    }

    /**
     * Запрос к админке: маршрут пакета или адрес под её префиксом (и доменом, если он задан). Если админка на корне
     * домена сайта (`path` пустой, `domain` не задан), её — только адреса без маршрута.
     */
    private static function isAdminRequest(Request $request): bool
    {
        $name = (string) $request->route()?->getName();

        // Карта сайта и robots.txt публичные: посетитель получает обычную страницу ошибки сайта.
        if (str_starts_with($name, 'fastleo.') && ! in_array($name, ['fastleo.sitemap', 'fastleo.sitemap.page', 'fastleo.robots'], true)) {
            return true;
        }

        $domain = config('fastleo.domain');

        if (is_string($domain) && $domain !== '' && strcasecmp($request->getHost(), $domain) !== 0) {
            return false;
        }

        $prefix = trim(config()->string('fastleo.path', 'fastleo'), '/');

        if ($prefix !== '') {
            return $request->is($prefix, $prefix.'/*');
        }

        // Админка на корне: на своём домене ей принадлежит любой адрес, на домене сайта — только адрес без маршрута
        // (ошибки маршрутов сайта остаются страницами сайта).
        return (is_string($domain) && $domain !== '') || $request->route() === null;
    }
}
