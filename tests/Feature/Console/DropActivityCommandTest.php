<?php

declare(strict_types=1);

use Fastleo\Console\DropActivityCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Создать таблицу и запись о миграции старого журнала.
 */
function oldActivityTable(): void
{
    Schema::create(DropActivityCommand::TABLE, function ($table): void {
        $table->id();
    });
    DB::table('migrations')->insert(['migration' => DropActivityCommand::MIGRATION, 'batch' => 1]);
}

it('says that there is nothing to remove when the table is not there', function (): void {
    $this->artisan('fastleo:drop-activity')
        ->expectsOutputToContain('Таблицы fastleo_activity нет — удалять нечего.')
        ->assertSuccessful();
});

it('drops the table of the old log and forgets its migration after the confirmation', function (): void {
    oldActivityTable();

    $this->artisan('fastleo:drop-activity')
        ->expectsConfirmation('Удалить таблицу fastleo_activity со всеми записями? Это нельзя отменить.', 'yes')
        ->expectsOutputToContain('Таблица fastleo_activity удалена.')
        ->assertSuccessful();

    expect(Schema::hasTable(DropActivityCommand::TABLE))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', DropActivityCommand::MIGRATION)->exists())->toBeFalse();
});

it('keeps the table when the confirmation is refused', function (): void {
    oldActivityTable();

    $this->artisan('fastleo:drop-activity')
        ->expectsConfirmation('Удалить таблицу fastleo_activity со всеми записями? Это нельзя отменить.', 'no')
        ->assertFailed();

    expect(Schema::hasTable(DropActivityCommand::TABLE))->toBeTrue();
});

it('drops the table without a question with --force', function (): void {
    oldActivityTable();

    $this->artisan('fastleo:drop-activity', ['--force' => true])->assertSuccessful();

    expect(Schema::hasTable(DropActivityCommand::TABLE))->toBeFalse();
});
