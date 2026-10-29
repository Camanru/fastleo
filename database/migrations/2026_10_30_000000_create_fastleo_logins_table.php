<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Входы в админку: с какого устройства и адреса, когда впервые и в последний раз.
 * По ним профиль показывает «где вы вошли», а вход с нового устройства — письмо.
 */
return new class extends Migration
{
    /**
     * Создаёт таблицу.
     */
    public function up(): void
    {
        Schema::create('fastleo_logins', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id', 36);
            $table->string('fingerprint', 40);
            $table->string('ip', 45);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();

            $table->unique(['user_id', 'fingerprint']);
        });
    }

    /**
     * Удаляет таблицу.
     */
    public function down(): void
    {
        Schema::dropIfExists('fastleo_logins');
    }
};
