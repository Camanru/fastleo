<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Closure;
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
use Illuminate\Support\Timebox;
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
     * Наименьшее время ответа в микросекундах, что бы ни стояло в `auth.timebox_duration` (по умолчанию 0,2 с).
     * Хеш пароля на обычном сервере занимает 0,2–0,4 с, и рамка в 0,2 с вокруг него ничего не скрывает: администратор
     * и неизвестный адрес отвечали бы за разное время. Секунда с запасом перекрывает любую настройку хеширования.
     */
    private const int MIN_RESPONSE_TIME = 1_000_000;

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

        // Поиск, брокер паролей (у него своя рамка, но она короче) и работа вместо брокера для неизвестного адреса
        // укладываются в одну рамку: ответ приходит за одно и то же время, есть такой администратор или нет.
        // Письмо уходит после ответа: его отправка (SMTP при очереди sync) времени не добавляет.
        $this->boxed(function () use ($email): void {
            if (UserLookup::adminByEmail($email) instanceof CanResetPassword) {
                $this->broker()->sendResetLink(['email' => $email], function (CanResetPassword $user, string $token): void {
                    defer(fn () => rescue(fn () => Notification::route('mail', $user->getEmailForPasswordReset())->notify(
                        new ResetPasswordNotification($token, $user->getEmailForPasswordReset(), $this->expireMinutes()),
                    )));
                });
            } else {
                $this->hashLikeBroker();
            }
        });

        return back()->with('status', __('fastleo::auth.forgot_sent'));
    }

    /**
     * Форма нового пароля по ссылке из письма.
     */
    public function form(Request $request, string $token): View
    {
        $email = $request->query('email');

        return view('fastleo::auth.reset-password', ['token' => $token, 'email' => is_string($email) ? $email : '']);
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

        $status = $this->boxed(function () use ($request, $email): string {
            // Модель без CanResetPassword письма не получала (см. send()) и брокер на ней бросил бы исключение.
            // Неизвестный адрес и не-администратор отвечают за то же время, что и администратор с неверным токеном.
            if (! UserLookup::adminByEmail($email) instanceof CanResetPassword) {
                $this->hashLikeBroker();

                return PasswordFacade::INVALID_USER;
            }

            return $this->broker()->reset(
                ['email' => $email, 'token' => $request->string('token')->toString(), 'password' => $request->string('password')->toString()],
                function (CanResetPassword&Authenticatable $user, string $password): void {
                    $user->forceFill(['password' => Hash::make($password)]);
                    $user->setRememberToken(Str::random(60));
                    $user->save();

                    event(new PasswordReset($user));
                },
            );
        });

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
     * Выполнить работу не быстрее `MIN_RESPONSE_TIME` (а если сайт задал `auth.timebox_duration` больше — не быстрее него).
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function boxed(Closure $callback): mixed
    {
        $duration = config('auth.timebox_duration', 200_000);

        return new Timebox()->call($callback, max(self::MIN_RESPONSE_TIME, is_numeric($duration) ? (int) $duration : 0));
    }

    /**
     * Работа брокера паролей для настоящего администратора, сделанная вхолостую: хеш токена (почти всё время брокера).
     * Нужна адресу, для которого брокер не вызывается, чтобы он не отвечал быстрее на хосте с медленным хешем.
     */
    private function hashLikeBroker(): void
    {
        Hash::make(Str::random(40));
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
