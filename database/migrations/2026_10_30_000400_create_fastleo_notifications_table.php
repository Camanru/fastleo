<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Уведомления администраторам (колокольчик в шапке): заголовок, текст, ссылка, прочитано ли.
 */
return new class extends Migration
{
    /**
     * Создаёт таблицу.
     */
    public function up(): void
    {
        Schema::create('fastleo_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id', 36);
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['user_id', 'read_at']);
        });
    }

    /**
     * Удаляет таблицу.
     */
    public function down(): void
    {
        Schema::dropIfExists('fastleo_notifications');
    }
};
