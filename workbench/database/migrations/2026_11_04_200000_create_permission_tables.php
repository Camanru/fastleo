<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Пять таблиц spatie/laravel-permission для демо и тестов ролей — миграция из самого пакета (он в `require-dev`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->migration()->up();
    }

    public function down(): void
    {
        $this->migration()->down();
    }

    private function migration(): Migration
    {
        return require __DIR__.'/../../../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
    }
};
