<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Двухфакторная защита администраторов: секрет приложения-аутентификатора и резервные коды.
 * Отдельная таблица, а не колонки users: секрет не попадает в toArray()/JSON модели сайта
 * и не требует от неё encrypted-каста.
 */
return new class extends Migration
{
    /**
     * Создаёт таблицу.
     */
    public function up(): void
    {
        Schema::create('fastleo_two_factor', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id', 36)->unique();
            $table->text('secret');
            $table->text('recovery_codes')->nullable();
            $table->unsignedBigInteger('last_step')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Удаляет таблицу.
     */
    public function down(): void
    {
        Schema::dropIfExists('fastleo_two_factor');
    }
};
