<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Категории демо-сайта.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->string('title');
            $table->string('code', 50)->nullable()->unique();
            $table->string('internal_code')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Удалить таблицу категорий.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
