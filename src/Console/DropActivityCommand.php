<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Fastleo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Удаляет таблицу `fastleo_activity`, оставшуюся на сайте от журнала изменений прежних версий, и запись о её миграции.
 * Сам пакет журнала больше не ведёт и таблицу не трогает.
 *
 * ```bash
 * php artisan fastleo:drop-activity          # спросит подтверждение
 * php artisan fastleo:drop-activity --force  # без вопроса
 * ```
 */
#[AsCommand(name: 'fastleo:drop-activity')]
#[Description('Удалить таблицу fastleo_activity, оставшуюся от журнала изменений прежних версий')]
#[Signature('fastleo:drop-activity {--force : Удалить без подтверждения}')]
final class DropActivityCommand extends Command
{
    /**
     * Таблица журнала прежних версий.
     */
    public const string TABLE = 'fastleo_activity';

    /**
     * Миграция, создававшая таблицу.
     */
    public const string MIGRATION = '2026_10_30_000200_create_fastleo_activity_table';

    /**
     * Удалить таблицу и запись о миграции.
     */
    public function handle(): int
    {
        app()->setLocale(Fastleo::LOCALE);

        if (! Schema::hasTable(self::TABLE)) {
            $this->components->info(__('fastleo::console.drop_activity_none', ['table' => self::TABLE]));

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->components->confirm(__('fastleo::console.drop_activity_confirm', ['table' => self::TABLE]))) {
            return self::FAILURE;
        }

        Schema::drop(self::TABLE);

        /** @var MigrationRepositoryInterface $migrations */
        $migrations = resolve('migration.repository');

        if ($migrations->repositoryExists()) {
            $migrations->delete((object) ['migration' => self::MIGRATION]);
        }

        $this->components->info(__('fastleo::console.drop_activity_done', ['table' => self::TABLE]));

        return self::SUCCESS;
    }
}
