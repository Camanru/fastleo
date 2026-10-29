<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Боты уведомлений администраторам (раздел «Уведомления»): мессенджер, токен (зашифрован), чаты получателей.
 */
return new class extends Migration
{
    /**
     * Создаёт таблицу.
     */
    public function up(): void
    {
        Schema::create('fastleo_notification_bots', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('messenger', 20);
            $table->text('token');
            $table->json('recipients');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Удаляет таблицу.
     */
    public function down(): void
    {
        Schema::dropIfExists('fastleo_notification_bots');
    }
};
