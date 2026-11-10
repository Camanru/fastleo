<?php

declare(strict_types=1);

namespace Fastleo\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Заголовки безопасности для всех страниц панели, включая вход.
 *
 * - `X-Frame-Options: SAMEORIGIN` — страницы встраиваются во фрейм только своим сайтом (защита от clickjacking);
 * - `X-Content-Type-Options: nosniff` — браузер не угадывает тип ответа;
 * - `Referrer-Policy: same-origin` — адреса панели не уходят на чужие сайты;
 * - `X-Robots-Tag: noindex, nofollow` — поисковики не индексируют панель;
 * - `Cache-Control: no-store, private` — после выхода страницы не открываются из кеша кнопкой «Назад»; исключение —
 *   переход на миниатюру менеджера файлов (`private, max-age`): он ведёт на публичный файл, а сетка из сотен картинок
 *   иначе запускала бы Laravel на каждую. Запоминается только ответ самого контроллера миниатюр (он помечает запрос
 *   признаком `CACHEABLE_REDIRECT`), а не любой переход на этом адресе: переход гостя на страницу входа остаётся `no-store`.
 */
final class SecurityHeaders
{
    /** Сколько браузер помнит переход на миниатюру. */
    private const int THUMBNAIL_SECONDS = 300;

    /** Признак запроса: контроллер миниатюр отвечает переходом на публичный файл, и его можно запомнить. */
    public const string CACHEABLE_REDIRECT = 'fastleo.cacheable_redirect';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');
        $headers->set('Cache-Control', $response->isRedirection() && $request->attributes->get(self::CACHEABLE_REDIRECT) === true ? 'private, max-age='.self::THUMBNAIL_SECONDS : 'no-store, private');

        return $response;
    }
}
