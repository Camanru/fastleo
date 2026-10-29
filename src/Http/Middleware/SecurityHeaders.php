<?php

declare(strict_types=1);

namespace Fastleo\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Заголовки безопасности для всех страниц админки, включая вход.
 *
 * - `X-Frame-Options: SAMEORIGIN` — страницы встраиваются во фрейм только своим сайтом (защита от clickjacking);
 * - `X-Content-Type-Options: nosniff` — браузер не угадывает тип ответа;
 * - `Referrer-Policy: same-origin` — адреса админки не уходят на чужие сайты;
 * - `X-Robots-Tag: noindex, nofollow` — поисковики не индексируют админку;
 * - `Cache-Control: no-store, private` — после выхода страницы не открываются из кеша кнопкой «Назад».
 */
final class SecurityHeaders
{
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
        $headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
