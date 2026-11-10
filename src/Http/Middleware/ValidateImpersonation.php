<?php

declare(strict_types=1);

namespace Fastleo\Http\Middleware;

use Closure;
use Fastleo\Auth\Impersonation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Пока владелец вошёл под другим пользователем («Войти как»), на каждом запросе проверяет, что подмена в порядке:
 * владелец на месте, он по-прежнему владелец и не менял пароль, срок не вышел. Иначе — выход из панели.
 *
 * Под подменой закрыто то, что записало бы данные от имени пользователя или отдало бы его учётную запись: профиль (почта,
 * пароль, двухфакторная защита, «выйти на других устройствах»), прочтение уведомлений, сохранение раскладки главной.
 * Двухфакторную защиту владелец уже прошёл, поэтому `RequireTwoFactor` пропускает такие запросы.
 */
final class ValidateImpersonation
{
    /**
     * Маршруты, закрытые под подменой.
     */
    private const array BLOCKED = ['fastleo.profile.*', 'fastleo.notifications.read', 'fastleo.notifications.read-all', 'fastleo.dashboard.layout.*'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Impersonation::active()) {
            return $next($request);
        }

        if (! Impersonation::valid()) {
            Impersonation::signOut();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(Response::HTTP_FORBIDDEN, __('fastleo::auth.no_access'));
            }

            return to_route('fastleo.login');
        }

        abort_if($request->routeIs(...self::BLOCKED), Response::HTTP_FORBIDDEN, __('fastleo::users.impersonate_blocked'));

        return $next($request);
    }
}
