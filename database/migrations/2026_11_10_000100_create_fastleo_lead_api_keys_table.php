<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Доступы других сайтов к приёму заявок («Доступы для сайтов»): ключ зашифрован (его видит пользователь панели и передаёт
 * разработчику сайта), для поиска по ключу есть хеш.
 */
return new class extends Migration
{
    /**
     * Создаёт таблицу.
     */
    public function up(): void
    {
        Schema::create('fastleo_lead_api_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('site')->nullable();
            $table->text('token');
            $table->string('token_hash', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Удаляет таблицу.
     */
    public function down(): void
    {
        Schema::dropIfExists('fastleo_lead_api_keys');
    }
};
