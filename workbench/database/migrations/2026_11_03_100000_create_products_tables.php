<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Поставщики, товары, отзывы о них и состав комплекта (выбор товара в блоке): правка в ячейке, быстрый переключатель, цвет строки, вычисляемый столбец, связанные таблицы.
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('note')->nullable();
            $table->string('portal_password')->nullable();
            $table->text('api_key')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('sku', 40)->nullable();
            $table->string('photo')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('stock')->default(0);
            $table->string('status', 20)->default('active');
            $table->boolean('is_visible')->default(true);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('product_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('author');
            $table->unsignedTinyInteger('rating')->default(5);
            $table->text('text')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->timestamps();
        });

        Schema::create('product_bundle_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('qty')->default(1);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Удалить таблицы.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_bundle_items');
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('products');
        Schema::dropIfExists('suppliers');
    }
};
