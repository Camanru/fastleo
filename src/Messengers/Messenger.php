<?php

declare(strict_types=1);

namespace Fastleo\Messengers;

use SensitiveParameter;

/**
 * Мессенджер, в который пишет бот: Telegram или Max.
 *
 * ```php
 * Messenger::Max->bot($token)->sendText('-71234567', 'Привет');
 * ```
 */
enum Messenger: string
{
    case Telegram = 'telegram';
    case Max = 'max';

    /**
     * Название для админки.
     */
    public function label(): string
    {
        return match ($this) {
            self::Telegram => 'Telegram',
            self::Max => 'Max',
        };
    }

    /**
     * Клиент бота с этим токеном.
     */
    public function bot(#[SensitiveParameter] string $token): Bot
    {
        return match ($this) {
            self::Telegram => new TelegramBot($token),
            self::Max => new MaxBot($token),
        };
    }

    /**
     * Регулярное выражение для токена бота (Telegram — `123456:ABC…` от @BotFather, Max — строка из кабинета).
     */
    public function tokenPattern(): string
    {
        return match ($this) {
            self::Telegram => '/^\d{5,15}:[A-Za-z0-9_-]{20,60}$/',
            self::Max => '/^[A-Za-z0-9_\-.~+\/=]{20,512}$/',
        };
    }

    /**
     * Регулярное выражение для идентификатора чата: в Telegram — число или @имя канала, в Max — только число.
     */
    public function chatPattern(): string
    {
        return match ($this) {
            self::Telegram => '/^(-?\d{1,20}|@[A-Za-z0-9_]{5,32})$/',
            self::Max => '/^-?\d{1,20}$/',
        };
    }
}
