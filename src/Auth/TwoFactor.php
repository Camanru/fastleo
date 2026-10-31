<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Fastleo\Support\Branding;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

/**
 * Двухфакторная защита входа: одноразовые коды TOTP (Google Authenticator, 1Password и т. п.)
 * и резервные коды на случай потери телефона.
 *
 * Код принимается один раз: шаг времени последнего принятого кода хранится и повторно не подходит.
 * Резервный код сгорает после использования; в базе лежат только его хеши.
 *
 * ```php
 * $twoFactor->isEnabled($user);          // включена ли защита
 * $twoFactor->verify($user, '123456');   // верен ли код из приложения
 * ```
 */
final readonly class TwoFactor
{
    /** Сколько резервных кодов выдаётся. */
    public const int RECOVERY_CODES = 8;

    /**
     * @param  Google2FA  $google2fa  генератор и проверка кодов
     */
    public function __construct(private Google2FA $google2fa) {}

    /**
     * Защита включена: приложение подтверждено кодом.
     */
    public function isEnabled(Authenticatable $user): bool
    {
        return $this->credential($user)?->confirmed_at !== null;
    }

    /**
     * Настройка начата, но не подтверждена кодом.
     */
    public function isPending(Authenticatable $user): bool
    {
        $credential = $this->credential($user);

        return $credential instanceof TwoFactorCredential && $credential->confirmed_at === null;
    }

    /**
     * Начать настройку: новый секрет (прежний неподтверждённый заменяется). Возвращает секрет для ввода вручную.
     * Включённую защиту этот метод не трогает — сначала её отключают.
     */
    public function begin(Authenticatable $user): string
    {
        if ($this->isEnabled($user)) {
            return $this->secretOf($this->credential($user));
        }

        $secret = $this->google2fa->generateSecretKey(32);

        TwoFactorCredential::query()->updateOrCreate(
            ['user_id' => $this->key($user)],
            ['secret' => $secret, 'recovery_codes' => null, 'last_step' => null, 'confirmed_at' => null],
        );

        return $secret;
    }

    /**
     * Секрет настройки, ещё не подтверждённой кодом, или null.
     */
    public function pendingSecret(Authenticatable $user): ?string
    {
        $credential = $this->credential($user);

        if (! $credential instanceof TwoFactorCredential || $credential->confirmed_at !== null) {
            return null;
        }

        // Секрет не расшифровывается (сменился ключ приложения): настройку нужно начать заново.
        return $this->secretOf($credential) ?: null;
    }

    /**
     * Адрес otpauth:// для приложения-аутентификатора.
     */
    public function otpauthUrl(Authenticatable $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(Branding::name(), UserLookup::emailOf($user), $secret);
    }

    /**
     * QR-код адреса otpauth:// как SVG для вставки в страницу.
     */
    public function qrSvg(Authenticatable $user, string $secret): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));

        return trim(Str::after($writer->writeString($this->otpauthUrl($user, $secret)), '?>'));
    }

    /**
     * Подтвердить настройку кодом из приложения: включает защиту и выдаёт резервные коды (показываются один раз).
     *
     * @return list<string>|null коды или null, если код неверен
     */
    public function confirm(Authenticatable $user, string $code): ?array
    {
        $credential = $this->credential($user);

        if (! $credential instanceof TwoFactorCredential || $credential->confirmed_at !== null || ! $this->accept($credential, $code)) {
            return null;
        }

        $credential->update(['confirmed_at' => now()]);

        return $this->regenerateRecoveryCodes($user);
    }

    /**
     * Проверить код из приложения (6 цифр). Каждый код принимается один раз.
     */
    public function verify(Authenticatable $user, string $code): bool
    {
        $credential = $this->credential($user);

        return $credential instanceof TwoFactorCredential && $credential->confirmed_at !== null && $this->accept($credential, $code);
    }

    /**
     * Проверить резервный код и сжечь его. Чтение и запись идут под блокировкой строки в транзакции:
     * два одновременных запроса с одним кодом не пройдут оба.
     */
    public function verifyRecoveryCode(Authenticatable $user, string $code): bool
    {
        $hash = $this->hashCode($code);

        return DB::transaction(function () use ($user, $hash): bool {
            $credential = TwoFactorCredential::query()->where('user_id', $this->key($user))->lockForUpdate()->first();

            if (! $credential instanceof TwoFactorCredential || $credential->confirmed_at === null) {
                return false;
            }

            $codes = $credential->recovery_codes ?? [];

            foreach ($codes as $index => $stored) {
                if (hash_equals($stored, $hash)) {
                    unset($codes[$index]);
                    $credential->update(['recovery_codes' => array_values($codes)]);

                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Выдать новые резервные коды, старые перестают действовать.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(Authenticatable $user): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $codes[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }

        $this->credential($user)?->update(['recovery_codes' => array_map($this->hashCode(...), $codes)]);

        return $codes;
    }

    /**
     * Сколько резервных кодов осталось.
     */
    public function remainingRecoveryCodes(Authenticatable $user): int
    {
        return count($this->credential($user)->recovery_codes ?? []);
    }

    /**
     * Отключить защиту и забыть секрет и коды.
     */
    public function disable(Authenticatable $user): void
    {
        TwoFactorCredential::query()->where('user_id', $this->key($user))->delete();
    }

    /**
     * Принять код TOTP, если он верен и новее последнего принятого (защита от повторного использования).
     * Шаг записывается условным обновлением: из двух одновременных запросов с одним кодом пройдёт один.
     *
     * @throws ValidationException если секрет не расшифровывается (сменился `APP_KEY`)
     */
    private function accept(TwoFactorCredential $credential, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        try {
            $secret = $credential->getAttribute('secret');
        } catch (DecryptException) {
            throw ValidationException::withMessages(['code' => __('fastleo::auth.two_factor_unreadable')]);
        }

        if (! is_string($secret)) {
            return false;
        }

        $step = $this->google2fa->verifyKeyNewer($secret, $code, $credential->last_step ?? 0, 1);

        if (! is_int($step)) {
            return false;
        }

        return TwoFactorCredential::query()
            ->whereKey($credential->getKey())
            ->where(fn (Builder $query): Builder => $query->whereNull('last_step')->orWhere('last_step', '<', $step))
            ->update(['last_step' => $step]) === 1;
    }

    /**
     * Секрет записи или пустая строка, если он не расшифровывается.
     */
    private function secretOf(?TwoFactorCredential $credential): string
    {
        try {
            $secret = $credential?->getAttribute('secret');
        } catch (DecryptException) {
            return '';
        }

        return is_string($secret) ? $secret : '';
    }

    /**
     * Хеш резервного кода: HMAC с ключом приложения (коды случайные и длинные — медленный хеш не нужен).
     */
    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', Str::lower(preg_replace('/\s+/', '', $code) ?? ''), config()->string('app.key'));
    }

    /**
     * Запись пользователя.
     */
    private function credential(Authenticatable $user): ?TwoFactorCredential
    {
        return TwoFactorCredential::query()->where('user_id', $this->key($user))->first();
    }

    /**
     * Ключ пользователя в таблице.
     */
    private function key(Authenticatable $user): string
    {
        $key = $user->getAuthIdentifier();

        return is_scalar($key) ? (string) $key : '';
    }
}
