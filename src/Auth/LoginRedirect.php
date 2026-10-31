<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use Fastleo\Support\Home;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Куда вести администратора после входа.
 *
 * Адрес возврата `url.intended` сессия делит с сайтом: сайт мог записать туда свою страницу.
 * В админку возвращаем только на её собственные адреса, иначе — на стартовую страницу (`Home`, по умолчанию дашборд).
 */
final class LoginRedirect
{
    /**
     * Редирект на запомненную страницу админки или на стартовую страницу.
     *
     * ```php
     * return LoginRedirect::intended($request);
     * ```
     */
    public static function intended(Request $request): RedirectResponse
    {
        $dashboard = route('fastleo.dashboard');
        $intended = $request->session()->pull('url.intended');

        if (is_string($intended) && (str_starts_with($intended, $dashboard.'/') || str_starts_with($intended, $dashboard.'?'))) {
            return redirect()->to($intended);
        }

        return redirect()->to(Home::url());
    }
}
