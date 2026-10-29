<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Темы статей (многие ко многим), полезные ссылки статьи (HasMany) и баннер статьи (HasOne).
     */
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->unique();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('article_topic', function (Blueprint $table): void {
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->primary(['article_id', 'topic_id']);
        });

        Schema::create('article_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('url')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('article_banners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('link')->nullable();
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Удалить таблицы.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_banners');
        Schema::dropIfExists('article_links');
        Schema::dropIfExists('article_topic');
        Schema::dropIfExists('topics');
    }
};
