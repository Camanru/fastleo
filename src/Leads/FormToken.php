<?php

declare(strict_types=1);

namespace Fastleo\Leads;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Метка формы заявки для скрытого поля `form_token`: зашифрованное время показа формы и номер сессии посетителя. Защита от ботов
 * без внешних сервисов: бот, собравший форму заранее или не показывавший её, метки нужного вида не имеет.
 *
 * ```blade
 * <input type="hidden" name="form_token" value="{{ \Fastleo\Leads\FormToken::make() }}">
 * ```
 */
final class FormToken
{
    /**
     * Метка для формы, показываемой сейчас: время показа и номер сессии посетителя.
     */
    public static function make(): string
    {
        return Crypt::encryptString(now()->timestamp.'|'.session()->getId());
    }

    /**
     * Расшифрованная метка: время показа и номер сессии; null — метки нет или она подделана.
     *
     * @return array{shown_at: int, session: string}|null
     */
    public static function read(?string $token): ?array
    {
        try {
            [$shownAt, $session] = explode('|', Crypt::decryptString((string) $token).'|', 3);
        } catch (DecryptException) {
            return null;
        }

        return ['shown_at' => (int) $shownAt, 'session' => $session];
    }
}
