<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Страницы демо-сайта: JSON-поля (вопросы и ответы, характеристики, блоки) и многоязычный заголовок.
     */
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->json('title');
            $table->string('slug')->unique();
            $table->json('faq')->nullable();
            $table->json('specs')->nullable();
            $table->json('content')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Удалить таблицу страниц.
     */
    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
