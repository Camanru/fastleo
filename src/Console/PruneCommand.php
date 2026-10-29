<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Activity\Activity;
use Fastleo\Auth\LoginHistory;
use Fastleo\Fastleo;
use Fastleo\Notifications\AdminNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Удаляет устаревшие служебные записи fastleo: историю входов и журнал изменений (и, по мере появления, другие служебные записи).
 * Пакет сам ставит команду в расписание раз в сутки; для работы нужен `schedule:run` в cron сайта.
 *
 * ```bash
 * php artisan fastleo:prune
 * ```
 */
#[AsCommand(name: 'fastleo:prune')]
#[Description('Удалить устаревшую историю входов и другие служебные записи fastleo')]
#[Signature('fastleo:prune')]
final class PruneCommand extends Command
{
    /**
     * Чистка по срокам из `fastleo.*.keep_days`.
     */
    public function handle(LoginHistory $logins): int
    {
        app()->setLocale(Fastleo::LOCALE);

        $activity = Activity::query()->toBase()->where('created_at', '<', now()->subDays(max(1, config()->integer('fastleo.activity.keep_days', 30))))->delete();

        $notifications = AdminNotification::query()->toBase()->where('created_at', '<', now()->subDays(max(1, config()->integer('fastleo.notifications.keep_days', 90))))->delete();

        $this->components->info(__('fastleo::console.prune_done', ['count' => $logins->prune() + $activity + $notifications]));

        return self::SUCCESS;
    }
}
