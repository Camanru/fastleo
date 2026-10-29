<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Каналы для публикаций (раздел «Каналы»): мессенджер, токен бота (зашифрован), номер канала.
 */
return new class extends Migration
{
    /**
     * Создаёт таблицу.
     */
    public function up(): void
    {
        Schema::create('fastleo_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('');
            $table->string('messenger', 20);
            $table->text('token');
            $table->string('chat_id', 40);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Удаляет таблицу.
     */
    public function down(): void
    {
        Schema::dropIfExists('fastleo_channels');
    }
};
