<?php

declare(strict_types=1);

namespace Fastleo\Messengers;

use RuntimeException;

/**
 * Мессенджер не принял запрос или не ответил. Текст — уже без токена бота; исходное исключение
 * не прикладывается (в его тексте адрес запроса с токеном).
 *
 * `$uncertain` — связь оборвалась после отправки запроса: сообщение могло дойти, повторять нельзя.
 * `$retryAfter` — мессенджер просит подождать столько секунд (слишком частые запросы).
 */
final class MessengerFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $uncertain = false, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
