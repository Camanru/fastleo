<?php

declare(strict_types=1);

namespace Fastleo\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession as LaravelAuthenticateSession;

/**
 * Завершает вход, если пароль администратора сменили на другом устройстве.
 *
 * Отличие от родителя — после выхода ведёт на вход админки, а не на `route('login')` сайта.
 */
final class AuthenticateSession extends LaravelAuthenticateSession
{
    /**
     * Адрес формы входа админки.
     */
    #[\Override]
    protected function redirectTo(Request $request): string
    {
        return route('fastleo.login');
    }
}
