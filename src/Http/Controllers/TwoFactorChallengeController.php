<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Auth\FailedLogins;
use Fastleo\Auth\FastleoUser;
use Fastleo\Auth\LoginRedirect;
use Fastleo\Auth\LoginThrottle;
use Fastleo\Auth\TwoFactor;
use Fastleo\Fastleo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Второй шаг входа: код из приложения-аутентификатора или резервный код.
 *
 * Состояние «пароль верен, ждём код» лежит в сессии (новый идентификатор после первого шага)
 * и живёт 10 минут. Пока код не введён, администратор не вошёл. Попытки считаются отдельно
 * от попыток пароля (ключ `2fa:{id}`), счётчики не сбрасываются первым шагом.
 */
final class TwoFactorChallengeController
{
    /** Ключ сессии с состоянием первого шага. */
    public const string SESSION_KEY = 'fastleo.two_factor';

    /** Сколько секунд даётся на ввод кода после пароля. */
    private const int LIFETIME = 600;

    /**
     * Форма ввода кода. Без первого шага — на форму входа.
     */
    public function create(Request $request): View|RedirectResponse
    {
        if ($this->pending($request) === null) {
            return to_route('fastleo.login');
        }

        return view('fastleo::auth.two-factor');
    }

    /**
     * Проверить код и войти.
     */
    public function store(Request $request, TwoFactor $twoFactor, LoginThrottle $throttle): RedirectResponse
    {
        $pending = $this->pending($request);
        $user = $pending === null ? null : Fastleo::guard()->getProvider()->retrieveById($pending['id']);

        if ($pending === null || ! $user instanceof FastleoUser || ! $user->canAccessFastleo() || ! $twoFactor->isEnabled($user)) {
            $request->session()->forget(self::SESSION_KEY);

            return to_route('fastleo.login');
        }

        $request->validate(['code' => ['required', 'string', 'max:64']], messages: ['code.*' => __('fastleo::auth.two_factor_failed')]);

        $account = '2fa:'.$pending['id'];
        $ip = (string) $request->ip();
        $seconds = $throttle->availableIn($account, $ip) ?? $throttle->hit($account, $ip);

        if ($seconds !== null) {
            return back()->withErrors(['code' => __('fastleo::auth.throttle', ['minutes' => (int) ceil($seconds / 60)])]);
        }

        $code = $request->string('code')->toString();

        if (! $twoFactor->verify($user, $code) && ! $twoFactor->verifyRecoveryCode($user, $code)) {
            FailedLogins::hit();

            return back()->withErrors(['code' => __('fastleo::auth.two_factor_failed')]);
        }

        $throttle->clear($account, $ip);
        $throttle->clear('id:'.$pending['id'], $ip);
        $request->session()->forget(self::SESSION_KEY);
        Fastleo::guard()->login($user, $pending['remember']);
        $request->session()->regenerate();

        return LoginRedirect::intended($request);
    }

    /**
     * Состояние первого шага или null, если его нет или оно устарело.
     *
     * @return array{id: string, remember: bool}|null
     */
    private function pending(Request $request): ?array
    {
        $state = $request->session()->get(self::SESSION_KEY);

        if (! is_array($state) || ! is_string($state['id'] ?? null) || ! is_int($state['at'] ?? null) || now()->getTimestamp() - $state['at'] > self::LIFETIME) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return ['id' => $state['id'], 'remember' => ($state['remember'] ?? false) === true];
    }
}
