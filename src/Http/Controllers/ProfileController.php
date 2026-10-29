<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Auth\LoginHistory;
use Fastleo\Auth\TwoFactor;
use Fastleo\Fastleo;
use Fastleo\Support\ValidationMessages;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Профиль администратора: имя, email, пароль, устройства и «выйти на других устройствах».
 *
 * Изменения, требующие подтверждения, проверяют текущий пароль; запросы с паролем ограничены
 * лимитом `fastleo-profile` (перебор пароля при краже сессии).
 */
final readonly class ProfileController
{
    /**
     * @param  LoginHistory  $logins  история входов
     * @param  TwoFactor  $twoFactor  двухфакторная защита
     */
    public function __construct(private LoginHistory $logins, private TwoFactor $twoFactor) {}

    /**
     * Страница профиля.
     */
    public function show(Request $request): View
    {
        $user = $this->user();

        $secret = $this->twoFactor->pendingSecret($user);

        return view('fastleo::profile.show', [
            'user' => $user,
            'twoFactorEnabled' => $this->twoFactor->isEnabled($user),
            'twoFactorSecret' => $secret,
            'twoFactorQr' => $secret === null ? null : $this->twoFactor->qrSvg($user, $secret),
            'recoveryLeft' => $this->twoFactor->remainingRecoveryCodes($user),
            'devices' => $this->logins->devices($user),
            'currentDevice' => $this->logins->currentFingerprint($request),
        ]);
    }

    /**
     * Сохранить имя и email. Смена email требует текущий пароль.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $this->user();
        $request->merge(['email' => Str::lower(trim($request->string('email')->toString()))]);
        $changesEmail = $request->string('email')->toString() !== Str::lower($this->attribute($user, 'email'));

        $validator = validator($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'current_password' => [$changesEmail ? 'required' : 'nullable', 'string', 'max:255', ...($changesEmail ? ['current_password:'.Fastleo::GUARD] : [])],
        ], ValidationMessages::all(), $this->attributes());

        // Проверка занятости вне правил: сообщение правила-замыкания у поля «email» заменяется сообщением правила email.
        // Без учёта регистра: на PostgreSQL и SQLite «Anna@» и «anna@» — разные строки.
        $validator->after(function (Validator $validator) use ($user, $request): void {
            if (! $validator->errors()->has('email')
                && $user::query()->whereRaw('lower(email) = ?', [Str::lower($request->string('email')->toString())])->whereKeyNot($user->getKey())->exists()) {
                $validator->errors()->add('email', ValidationMessages::line('unique'));
            }
        });
        $validator->validateWithBag('profile');

        $user->forceFill(['name' => $request->string('name')->toString(), 'email' => $request->string('email')->toString()])->save();

        return to_route('fastleo.profile.show')->with('fastleo.success', __('fastleo::profile.saved'));
    }

    /**
     * Сменить пароль. Остальные устройства выходят, текущее остаётся.
     */
    public function password(Request $request): RedirectResponse
    {
        $user = $this->user();

        $request->validateWithBag('password', [
            'current_password' => ['required', 'string', 'max:255', 'current_password:'.Fastleo::GUARD],
            'password' => ['required', 'string', 'max:255', 'confirmed', 'different:current_password', Password::defaults()],
        ], ValidationMessages::all(), $this->attributes());

        $password = $request->string('password')->toString();
        $user->forceFill(['password' => Hash::make($password)]);
        $user->setRememberToken(Str::random(60));
        $user->save();

        $this->endOtherSessions($request, $password);

        return to_route('fastleo.profile.show')->with('fastleo.success', __('fastleo::profile.password_changed'));
    }

    /**
     * Выйти на всех устройствах, кроме текущего.
     */
    public function logoutOthers(Request $request): RedirectResponse
    {
        $request->validateWithBag('sessions', [
            'current_password' => ['required', 'string', 'max:255', 'current_password:'.Fastleo::GUARD],
        ], ValidationMessages::all(), $this->attributes());

        $this->endOtherSessions($request, $request->string('current_password')->toString());

        return to_route('fastleo.profile.show')->with('fastleo.success', __('fastleo::profile.others_logged_out'));
    }

    /**
     * Завершить остальные сеансы (смена хеша пароля) и забыть их устройства.
     */
    private function endOtherSessions(Request $request, string $password): void
    {
        Fastleo::guard()->logoutOtherDevices($password);
        $this->logins->forgetOthers($this->user(), $request);
    }

    /**
     * Вошедший администратор как Eloquent-модель (админка работает только с такими, см. `UserModel`).
     */
    private function user(): Model&Authenticatable
    {
        $user = Fastleo::guard()->user();

        abort_unless($user instanceof Model, 403);

        return $user;
    }

    /**
     * Строковый атрибут модели или пустая строка.
     */
    private function attribute(Model $user, string $name): string
    {
        $value = $user->getAttribute($name);

        return is_string($value) ? $value : '';
    }

    /**
     * Названия полей для текстов ошибок.
     *
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return [
            'name' => __('fastleo::profile.name'),
            'email' => __('fastleo::profile.email'),
            'current_password' => __('fastleo::profile.current_password'),
            'password' => __('fastleo::profile.new_password'),
        ];
    }
}
