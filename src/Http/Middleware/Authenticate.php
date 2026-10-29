<?php

declare(strict_types=1);

namespace Fastleo\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as LaravelAuthenticate;
use Illuminate\Http\Request;

/**
 * Пускает дальше только вошедших через guard fastleo, гостя ведёт на вход админки.
 *
 * Laravel сам переключает guard по умолчанию на fastleo (`Auth::shouldUse()`),
 * поэтому дальше `auth()->user()` и `AuthenticateSession` работают с администратором.
 * Статический `redirectUsing()` родителя не используется — он общий с сайтом.
 */
final class Authenticate extends LaravelAuthenticate
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
