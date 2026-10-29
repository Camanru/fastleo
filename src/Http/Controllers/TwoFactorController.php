<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Auth\TwoFactor;
use Fastleo\Fastleo;
use Fastleo\Support\ValidationMessages;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Включение, отключение и резервные коды двухфакторной защиты в профиле.
 *
 * Включить, получить новые коды и отключить можно только с текущим паролем;
 * запросы ограничены лимитом `fastleo-profile`.
 */
final readonly class TwoFactorController
{
    /**
     * @param  TwoFactor  $twoFactor  двухфакторная защита
     */
    public function __construct(private TwoFactor $twoFactor) {}

    /**
     * Начать настройку: создаёт секрет, профиль покажет QR-код и поле подтверждения.
     */
    public function enable(Request $request): RedirectResponse
    {
        $this->confirmPassword($request);

        if (! $this->twoFactor->isEnabled($this->user())) {
            $this->twoFactor->begin($this->user());
        }

        return to_route('fastleo.profile.show');
    }

    /**
     * Подтвердить настройку кодом из приложения (и текущим паролем): защита включается, резервные коды показываются один раз.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $this->confirmPassword($request);
        $request->validateWithBag('two_factor', ['code' => ['required', 'string', 'max:32']], $this->messages(), ['code' => __('fastleo::profile.two_factor_code')]);

        try {
            $codes = $this->twoFactor->confirm($this->user(), $request->string('code')->toString());
        } catch (ValidationException $exception) {
            // Секрет не читается (сменён APP_KEY): ошибку показывает форма подтверждения, а не общий мешок.
            throw $exception->errorBag('two_factor');
        }

        if ($codes === null) {
            return to_route('fastleo.profile.show')->withErrors(['code' => __('fastleo::auth.two_factor_failed')], 'two_factor');
        }

        return to_route('fastleo.profile.show')
            ->with('fastleo.success', __('fastleo::profile.two_factor_enabled'))
            ->with('fastleo.recovery_codes', $codes);
    }

    /**
     * Выдать новые резервные коды (старые перестают действовать).
     */
    public function recoveryCodes(Request $request): RedirectResponse
    {
        $this->confirmPassword($request);

        if (! $this->twoFactor->isEnabled($this->user())) {
            return to_route('fastleo.profile.show');
        }

        return to_route('fastleo.profile.show')->with('fastleo.recovery_codes', $this->twoFactor->regenerateRecoveryCodes($this->user()));
    }

    /**
     * Отключить защиту.
     */
    public function disable(Request $request): RedirectResponse
    {
        $this->confirmPassword($request);
        $this->twoFactor->disable($this->user());

        return to_route('fastleo.profile.show')->with('fastleo.success', __('fastleo::profile.two_factor_disabled'));
    }

    /**
     * Проверить текущий пароль из формы.
     */
    private function confirmPassword(Request $request): void
    {
        $request->validateWithBag('two_factor', [
            'current_password' => ['required', 'string', 'max:255', 'current_password:'.Fastleo::GUARD],
        ], $this->messages(), ['current_password' => __('fastleo::profile.current_password')]);
    }

    /**
     * Вошедший администратор.
     */
    private function user(): Authenticatable
    {
        $user = Fastleo::guard()->user();

        abort_unless($user instanceof Authenticatable, 403);

        return $user;
    }

    /**
     * Тексты ошибок формы.
     *
     * @return array<string, string|array<string, string>>
     */
    private function messages(): array
    {
        return ValidationMessages::all();
    }
}
