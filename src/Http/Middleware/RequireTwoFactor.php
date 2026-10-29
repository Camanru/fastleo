<?php

declare(strict_types=1);

namespace Fastleo\Http\Middleware;

use Closure;
use Fastleo\Auth\TwoFactor;
use Fastleo\Fastleo;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * При `fastleo.auth.two_factor_required` отправляет администратора без двухфакторной защиты
 * в профиль: пока не включит, остальные страницы недоступны (выход и профиль открыты).
 */
final readonly class RequireTwoFactor
{
    /**
     * @param  TwoFactor  $twoFactor  двухфакторная защита
     */
    public function __construct(private TwoFactor $twoFactor) {}

    /**
     * Пропускает запрос, если защита не обязательна, уже включена или открыта страница профиля/выхода.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Fastleo::guard()->user();

        if (! config()->boolean('fastleo.auth.two_factor_required')
            || ! $user instanceof Authenticatable
            || $request->routeIs('fastleo.profile.*', 'fastleo.logout')
            || $this->twoFactor->isEnabled($user)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, __('fastleo::profile.two_factor_required'));
        }

        return to_route('fastleo.profile.show')->with('fastleo.error', __('fastleo::profile.two_factor_required'));
    }
}
