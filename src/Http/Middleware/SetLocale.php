<?php

declare(strict_types=1);

namespace Fastleo\Http\Middleware;

use Closure;
use Fastleo\Fastleo;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Админка всегда на русском, даже если у сайта другая локаль: тексты пакета есть только в ru.
 */
final class SetLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(Fastleo::LOCALE);

        return $next($request);
    }
}
