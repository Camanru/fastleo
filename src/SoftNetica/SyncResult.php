<?php

declare(strict_types=1);

namespace Fastleo\SoftNetica;

use Fastleo\Fastleo;

/**
 * Чем закончилась синхронизация с SoftNetica.
 */
enum SyncResult: string
{
    /** Панель приняла отчёт и прислала данные клиента. */
    case Connected = 'connected';

    /** Токен неверный, перевыпущен или отозван, либо сайт отключён в SoftNetica (401). */
    case Rejected = 'rejected';

    /** Слишком часто (429): повторим в следующий раз. */
    case Throttled = 'throttled';

    /** Панель не приняла отчёт (422) или ответила не по протоколу. */
    case Invalid = 'invalid';

    /** Нет связи: таймаут, ошибка сети, ответ 5xx. */
    case Offline = 'offline';

    /** Подключение выключено или токена нет. */
    case NotConfigured = 'not_configured';

    /** Адрес панели в конфиге недопустим (не https). */
    case BadUrl = 'bad_url';

    /**
     * Подпись для администратора.
     */
    public function label(): string
    {
        return __('fastleo::softnetica.result_'.$this->value, [], Fastleo::LOCALE);
    }

    /**
     * Данные клиента сохранены и свежи.
     */
    public function isConnected(): bool
    {
        return $this === self::Connected;
    }
}
