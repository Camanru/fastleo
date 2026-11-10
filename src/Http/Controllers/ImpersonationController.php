<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Auth\Impersonation;
use Fastleo\Support\Home;
use Illuminate\Http\RedirectResponse;

/**
 * «Войти как»: возврат владельца в свою учётную запись. Начинается подмена действием «Войти как» в разделе пользователей.
 */
final class ImpersonationController
{
    /**
     * `POST /fastleo/impersonate/leave`. Без подмены — просто на стартовую страницу.
     */
    public function leave(): RedirectResponse
    {
        $url = Impersonation::returnUrl();

        if (! Impersonation::leave()) {
            return redirect()->to(Home::url());
        }

        return redirect()->to($url ?? Home::url())->with('fastleo.success', __('fastleo::users.impersonate_left'));
    }
}
