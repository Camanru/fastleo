<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Статьи демо-сайта: по колонке на каждый базовый и специальный тип поля.
     */
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20)->default('news');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->nullable()->unique();
            $table->text('excerpt')->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('tags')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->decimal('price', 10, 2)->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(false);
            $table->date('published_on')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->time('opens_at')->nullable();
            $table->string('author_email')->nullable();
            $table->string('source_url')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('color', 9)->nullable();
            $table->string('layout', 20)->default('wide');
            $table->unsignedTinyInteger('rating')->default(0);
            $table->json('keywords')->nullable();
            $table->text('body')->nullable();
            $table->text('head_code')->nullable();
            $table->longText('content')->nullable();
            $table->string('cover')->nullable();
            $table->json('gallery')->nullable();
            $table->json('seo')->nullable();
            $table->string('attachment')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Удалить таблицу статей.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
