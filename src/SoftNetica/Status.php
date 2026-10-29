<?php

declare(strict_types=1);

namespace Fastleo\SoftNetica;

use Carbon\CarbonImmutable;

/**
 * Состояние подключения для страницы настроек.
 *
 * @phpstan-type Snapshot array{hasToken: bool, rejected: bool, connected: bool, syncedAt: ?CarbonImmutable, attemptedAt: ?CarbonImmutable, result: ?SyncResult, stale: bool}
 */
final class Status
{
    /**
     * Снимок состояния: есть ли токен, отклонён ли он, когда была последняя синхронизация и чем закончилась последняя попытка.
     *
     * @return Snapshot
     */
    public static function current(): array
    {
        $state = resolve(SyncState::class);
        $token = Connection::token();

        return [
            'hasToken' => $token !== '',
            'rejected' => $token !== '' && $state->isRejected($token),
            'connected' => Connection::connected(),
            'syncedAt' => $state->syncedAt(),
            'attemptedAt' => $state->attemptedAt(),
            'result' => $state->lastResult(),
            'stale' => $state->isStale(),
        ];
    }
}
