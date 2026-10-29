<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Fastleo;
use Fastleo\SoftNetica\Connection;
use Fastleo\SoftNetica\Synchronizer;
use Fastleo\SoftNetica\SyncResult;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Отправляет отчёт о сайте в SoftNetica и получает данные клиента. Пакет сам ставит команду в расписание раз в 5 минут,
 * но только когда сайт подключён (токен введён и не отклонён); для работы нужен `schedule:run` в cron сайта.
 * Выполняется сразу, без очереди: отчёт должен дойти, даже если сломалась очередь.
 *
 * ```bash
 * php artisan fastleo:softnetica-sync
 * ```
 */
#[AsCommand(name: 'fastleo:softnetica-sync')]
#[Description('Отправить отчёт в SoftNetica и получить данные клиента (если введён токен подключения)')]
#[Signature('fastleo:softnetica-sync')]
final class SoftNeticaSyncCommand extends Command
{
    /**
     * Синхронизация; без подключения ничего не делает.
     */
    public function handle(Synchronizer $synchronizer): int
    {
        app()->setLocale(Fastleo::LOCALE);

        if (! Connection::connected()) {
            $this->components->info(__('fastleo::softnetica.command_skipped'));

            return self::SUCCESS;
        }

        $result = $synchronizer->run();

        $result->isConnected() ? $this->components->info($result->label()) : $this->components->error($result->label());

        return in_array($result, [SyncResult::Connected, SyncResult::NotConfigured, SyncResult::Throttled], true) ? self::SUCCESS : self::FAILURE;
    }
}
