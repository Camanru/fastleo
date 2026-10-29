<?php

declare(strict_types=1);

namespace Fastleo\Http\Middleware;

use Closure;
use Fastleo\Auth\FastleoUser;
use Fastleo\Fastleo;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Проверяет `canAccessFastleo()` на каждом запросе: у администратора могли снять права после входа.
 */
final class EnsureCanAccessFastleo
{
    /**
     * Без доступа — выход, новая сессия и форма входа с объяснением (для JSON — 403).
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Fastleo::guard()->user();

        if ($user instanceof FastleoUser && $user->canAccessFastleo()) {
            return $next($request);
        }

        Fastleo::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, __('fastleo::auth.no_access'));
        }

        return to_route('fastleo.login')->withErrors(['email' => __('fastleo::auth.no_access')]);
    }
}
