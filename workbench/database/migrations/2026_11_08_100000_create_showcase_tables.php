<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Таблицы витрины возможностей: четыре раздела «Витрина: поля» (по таблице на группу полей)
     * и задачи для «Витрина: список и действия» и отчёта.
     */
    public function up(): void
    {
        Schema::create('text_samples', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 80);
            $table->string('slug')->nullable()->unique();
            $table->string('subtitle')->nullable();
            $table->string('sku', 40)->nullable();
            $table->string('internal_code', 40)->nullable();
            $table->string('note')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('price', 10, 2)->nullable();
            $table->unsignedTinyInteger('rating')->default(5);
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website')->nullable();
            $table->string('password')->nullable();
            $table->text('api_key')->nullable();
            $table->string('color', 7)->nullable();
            $table->text('snippet')->nullable();
            $table->string('source', 20)->default('admin');
            $table->string('promo_code', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('choice_samples', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('size', 5)->nullable();
            $table->json('colors')->nullable();
            $table->string('stage', 20)->nullable();
            $table->unsignedInteger('shelf')->default(0);
            $table->string('brand', 20)->nullable();
            $table->string('model', 20)->nullable();
            $table->unsignedTinyInteger('priority')->default(2);
            $table->string('delivery', 20)->default('pickup');
            $table->string('address')->nullable();
            $table->boolean('is_gift')->default(false);
            $table->string('gift_note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('labels')->nullable();
            $table->date('available_on')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->time('opens_at')->nullable();
            $table->timestamps();
        });

        Schema::create('media_samples', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('cover')->nullable();
            $table->string('cover_alt')->nullable();
            $table->string('photo')->nullable();
            $table->json('gallery')->nullable();
            $table->string('document')->nullable();
            $table->string('video')->nullable();
            $table->timestamps();
        });

        Schema::create('json_samples', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->json('subtitle')->nullable();
            $table->longText('content')->nullable();
            $table->text('notes')->nullable();
            $table->json('plans')->nullable();
            $table->text('specs')->nullable();
            $table->json('body')->nullable();
            $table->json('seo')->nullable();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('new')->index();
            $table->unsignedTinyInteger('priority')->default(2);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->date('due_on')->nullable()->index();
            $table->unsignedSmallInteger('estimate')->nullable();
            $table->boolean('is_urgent')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Удалить таблицы витрины.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('json_samples');
        Schema::dropIfExists('media_samples');
        Schema::dropIfExists('choice_samples');
        Schema::dropIfExists('text_samples');
    }
};
