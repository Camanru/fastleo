<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Индекс для раздела «Уведомления» и колокольчика: уведомления одного пользователя, новые сверху.
 */
return new class extends Migration
{
    /**
     * Добавляет индекс (user_id, created_at). Имя короткое: автоматическое с длинным префиксом таблиц сайта
     * превысило бы лимит MySQL в 64 символа.
     */
    public function up(): void
    {
        Schema::table('fastleo_notifications', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at'], 'fastleo_notif_user_created');
        });
    }

    /**
     * Удаляет индекс.
     */
    public function down(): void
    {
        Schema::table('fastleo_notifications', function (Blueprint $table): void {
            $table->dropIndex('fastleo_notif_user_created');
        });
    }
};
