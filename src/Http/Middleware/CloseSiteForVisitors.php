<?php

declare(strict_types=1);

namespace Fastleo\Http\Middleware;

use Closure;
use Fastleo\Auth\FastleoUser;
use Fastleo\Closing\SiteClosing;
use Fastleo\Fastleo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сайт закрыт в настройках: посетители получают 503 («Сайт временно не работает»; запросам JSON — ответ JSON),
 * админка и вошедшие администраторы работают как обычно. Вошедший администратор видит сайт как всегда, а в шаблоны
 * приходит переменная `$fastleoSiteClosed` — сайт может показать пометку «посетителям сайт недоступен».
 */
final class CloseSiteForVisitors
{
    /**
     * @param  Closure(Request): Response  $next
     * @param  string  $mode  `json` — отвечать JSON всегда (группа `api`), иначе — по заголовку `Accept`
     */
    public function handle(Request $request, Closure $next, string $mode = ''): Response
    {
        if ($this->isAdminPanel($request) || ! SiteClosing::closed()) {
            return $next($request);
        }

        if ($this->isAdmin($request)) {
            View::share('fastleoSiteClosed', true);

            return $next($request);
        }

        if ($mode === 'json' || $request->expectsJson()) {
            return response()->json(['message' => SiteClosing::message()], Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '3600']);
        }

        return response()->view('fastleo::site-closed', ['message' => SiteClosing::message()], Response::HTTP_SERVICE_UNAVAILABLE)
            ->header('Retry-After', '3600');
    }

    /**
     * Запрос идёт в админку (маршруты `fastleo.*`).
     */
    private function isAdminPanel(Request $request): bool
    {
        return $request->routeIs('fastleo.*');
    }

    /**
     * В запросе вошёл администратор панели.
     */
    private function isAdmin(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $user = Fastleo::guard()->user();

        return $user instanceof FastleoUser && $user->canAccessFastleo();
    }
}
