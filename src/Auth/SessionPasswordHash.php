<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use Fastleo\Fastleo;
use Illuminate\Auth\Events\Login;

/**
 * Записывает в сеанс хеш пароля при каждом входе в панель, в том числе по cookie «Запомнить меня»:
 * по этому хешу `AuthenticateSession` закрывает сеанс, когда пароль сменили.
 */
final readonly class SessionPasswordHash
{
    /**
     * Слушатель события входа: хеш пароля вошедшего пользователя — в сеанс запроса.
     */
    public function handle(Login $event): void
    {
        $request = request();
        $password = $event->user->getAuthPassword();

        if ($event->guard !== Fastleo::GUARD || ! $request->hasSession() || $password === '') {
            return;
        }

        $request->session()->put('password_hash_'.Fastleo::GUARD, Fastleo::guard()->hashPasswordForCookie($password));
    }
}
