<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Auth\ResetPasswordNotification;
use Fastleo\Auth\UserLookup;
use Fastleo\Fastleo;
use Fastleo\Support\ValidationMessages;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password as PasswordFacade;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Восстановление пароля администратора по ссылке из письма.
 *
 * Ответ на запрос ссылки всегда одинаковый — по нему нельзя узнать, есть ли такой администратор.
 * Письмо уходит только тем, у кого есть доступ в админку; запросы ограничены лимитом `fastleo-password`.
 */
final class PasswordResetController
{
    /**
     * Форма «Забыли пароль?».
     */
    public function request(): View
    {
        return view('fastleo::auth.forgot-password');
    }

    /**
     * Отправить ссылку на сброс, если такой администратор есть.
     */
    public function send(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'max:255']], $this->messages(), $this->attributes());

        $email = Str::lower(trim($request->string('email')->toString()));
        $user = UserLookup::adminByEmail($email);

        if ($user instanceof CanResetPassword) {
            $this->broker()->sendResetLink(['email' => $email], function (CanResetPassword $user, string $token): void {
                rescue(fn () => Notification::route('mail', $user->getEmailForPasswordReset())->notify(
                    new ResetPasswordNotification($token, $user->getEmailForPasswordReset(), $this->expireMinutes()),
                ));
            });
        }

        return back()->with('status', __('fastleo::auth.forgot_sent'));
    }

    /**
     * Форма нового пароля по ссылке из письма.
     */
    public function form(Request $request, string $token): View
    {
        return view('fastleo::auth.reset-password', ['token' => $token, 'email' => $request->string('email')->toString()]);
    }

    /**
     * Сохранить новый пароль. Остальные сеансы завершаются: хеш пароля и токен «запомнить» меняются.
     */
    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults()],
        ], $this->messages(), $this->attributes());

        $email = Str::lower(trim($request->string('email')->toString()));

        if (! UserLookup::adminByEmail($email) instanceof Authenticatable) {
            return $this->invalid();
        }

        $status = $this->broker()->reset(
            ['email' => $email, 'token' => $request->string('token')->toString(), 'password' => $request->string('password')->toString()],
            function (CanResetPassword&Authenticatable $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password)]);
                $user->setRememberToken(Str::random(60));
                $user->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== PasswordFacade::PASSWORD_RESET) {
            return $this->invalid();
        }

        return to_route('fastleo.login')->with('status', __('fastleo::auth.reset_done'));
    }

    /**
     * Ответ на неверную или устаревшую ссылку — один и тот же для любой причины.
     */
    private function invalid(): RedirectResponse
    {
        return back()->withErrors(['email' => __('fastleo::auth.reset_invalid')])->onlyInput('email');
    }

    /**
     * Брокер паролей guard fastleo.
     */
    private function broker(): PasswordBroker
    {
        /** @var PasswordBroker */
        return PasswordFacade::broker(Fastleo::GUARD);
    }

    /**
     * Сколько минут действует ссылка.
     */
    private function expireMinutes(): int
    {
        return config()->integer('auth.passwords.'.Fastleo::GUARD.'.expire', 60);
    }

    /**
     * Тексты ошибок формы.
     *
     * @return array<string, string|array<string, string>>
     */
    private function messages(): array
    {
        return [...ValidationMessages::all(), 'email.required' => __('fastleo::auth.email_required')];
    }

    /**
     * Названия полей для текстов ошибок.
     *
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return ['email' => __('fastleo::auth.email'), 'password' => __('fastleo::auth.new_password')];
    }
}
