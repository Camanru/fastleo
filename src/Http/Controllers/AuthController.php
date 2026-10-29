<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Auth\FailedLogins;
use Fastleo\Auth\FastleoUser;
use Fastleo\Auth\LoginRedirect;
use Fastleo\Auth\LoginThrottle;
use Fastleo\Auth\TwoFactor;
use Fastleo\Auth\UserLookup;
use Fastleo\Fastleo;
use Fastleo\Http\Requests\LoginRequest;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Вход в админку и выход.
 */
final class AuthController
{
    /**
     * Форма входа. Вошедший администратор сразу попадает на дашборд.
     */
    public function create(): View|RedirectResponse
    {
        if ($this->currentAdmin() instanceof FastleoUser) {
            return to_route('fastleo.dashboard');
        }

        return view('fastleo::auth.login');
    }

    /**
     * Проверка email и пароля.
     *
     * Защита: лимиты попыток (попытка засчитывается до проверки пароля),
     * только точное совпадение email, хеширование пароля и для несуществующего
     * пользователя (по времени ответа не узнать, есть ли учётная запись),
     * одинаковая ошибка для неверного пароля и пользователя без доступа,
     * новая сессия после входа. Если у администратора включена двухфакторная защита,
     * вход не выполняется, пока не введён код (`TwoFactorChallengeController`).
     */
    public function store(LoginRequest $request, LoginThrottle $throttle, TwoFactor $twoFactor): RedirectResponse
    {
        $email = $request->email();
        $password = $request->password();
        $ip = (string) $request->ip();
        $guard = Fastleo::guard();
        $provider = $guard->getProvider();

        $user = UserLookup::byEmail($email);

        $account = $user instanceof Authenticatable ? 'id:'.$this->identifierOf($user) : $email;

        $seconds = $throttle->availableIn($account, $ip) ?? $throttle->hit($account, $ip);

        if ($seconds !== null) {
            return back()
                ->withErrors(['email' => __('fastleo::auth.throttle', ['minutes' => (int) ceil($seconds / 60)])])
                ->onlyInput('email');
        }

        if (! $user instanceof Authenticatable || in_array($user->getAuthPassword(), ['', null], true)) {
            $this->dummyHash();

            return $this->failed();
        }

        $credentials = ['email' => $email, 'password' => $password];

        if (! $this->passwordMatches($provider, $user, $credentials)
            || ! $user instanceof FastleoUser
            || ! $user->canAccessFastleo()) {
            return $this->failed();
        }

        $provider->rehashPasswordIfRequired($user, $credentials);

        // Пароль верен, но вход ещё не выполнен: счётчики попыток не сбрасываем, пока не пройден второй шаг.
        if ($twoFactor->isEnabled($user)) {
            $request->session()->regenerate();
            $request->session()->put(TwoFactorChallengeController::SESSION_KEY, [
                'id' => $this->identifierOf($user),
                'remember' => $request->boolean('remember'),
                'at' => now()->getTimestamp(),
            ]);

            return to_route('fastleo.two-factor.challenge');
        }

        $throttle->clear($account, $ip);
        $guard->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return LoginRedirect::intended($request);
    }

    /**
     * Выход: завершает вход guard fastleo, сессию и меняет CSRF-токен.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Fastleo::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('fastleo.login');
    }

    /**
     * Верен ли пароль. Особые настройки хеширования сайта (чужой алгоритм хеша, предел длины пароля)
     * дают исключение: для входа это «неверный пароль», а не ошибка 500, по которой видно, что email есть.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function passwordMatches(UserProvider $provider, Authenticatable $user, array $credentials): bool
    {
        try {
            return $provider->validateCredentials($user, $credentials);
        } catch (InvalidArgumentException|RuntimeException) {
            // Хеш чужого алгоритма отвергается без вычислений: время ответа выравнивается фиктивным хешированием.
            $this->dummyHash();

            return false;
        }
    }

    /**
     * Фиктивное хеширование для несуществующего пользователя и пользователя без пароля:
     * ответ занимает столько же времени, сколько проверка настоящего пароля.
     */
    private function dummyHash(): void
    {
        try {
            Hash::make(Str::random(40));
        } catch (InvalidArgumentException|RuntimeException) {
            // Хеширование не настроено под такой пароль — время ответа уже не отличается.
        }
    }

    /**
     * Вошедший администратор с доступом или null.
     */
    private function currentAdmin(): ?FastleoUser
    {
        $user = Fastleo::guard()->user();

        return $user instanceof FastleoUser && $user->canAccessFastleo() ? $user : null;
    }

    /**
     * Ответ на неверный email, пароль или отсутствие доступа — один и тот же.
     */
    private function failed(): RedirectResponse
    {
        FailedLogins::hit();

        return back()
            ->withErrors(['email' => __('fastleo::auth.failed')])
            ->onlyInput('email');
    }

    /**
     * Идентификатор пользователя строкой для ключа счётчика.
     */
    private function identifierOf(Authenticatable $user): string
    {
        $identifier = $user->getAuthIdentifier();

        return is_scalar($identifier) ? (string) $identifier : '';
    }
}
