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
 * `$reason` — машинный код ошибки из ответа мессенджера (Max: `attachment.not.ready`), если он есть: текст `message`
 * в ответе бывает другим («Key: errors.process…»), поэтому решать по тексту нельзя.
 */
final class MessengerFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $uncertain = false, public readonly ?int $retryAfter = null, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }
}
