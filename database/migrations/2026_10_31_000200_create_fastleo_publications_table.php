<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Публикации в каналы (раздел «Публикации») и их доставки — по одной на канал: статус, номера отправленных
 * сообщений (чтобы повтор продолжал с места сбоя, а правка и удаление нашли пост), ошибка.
 */
return new class extends Migration
{
    /**
     * Создаёт таблицы.
     */
    public function up(): void
    {
        Schema::create('fastleo_publications', function (Blueprint $table): void {
            $table->id();
            $table->text('text');
            $table->json('images')->nullable();
            $table->string('button_label', 128)->nullable();
            $table->string('button_url', 2048)->nullable();
            $table->json('channel_ids')->nullable();
            $table->string('source_resource', 100)->nullable();
            $table->string('source_id', 36)->nullable();
            $table->string('user_id', 36)->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamps();

            $table->index(['source_resource', 'source_id']);
        });

        Schema::create('fastleo_publication_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('publication_id')->constrained('fastleo_publications')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('fastleo_channels')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('layout', 20)->nullable();
            $table->json('message_ids')->nullable();
            $table->json('media_tokens')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['publication_id', 'channel_id']);
        });
    }

    /**
     * Удаляет таблицы.
     */
    public function down(): void
    {
        Schema::dropIfExists('fastleo_publication_deliveries');
        Schema::dropIfExists('fastleo_publications');
    }
};
