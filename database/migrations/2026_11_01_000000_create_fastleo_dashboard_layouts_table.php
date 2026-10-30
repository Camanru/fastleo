<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Раскладка дашборда администратора: порядок, размер и скрытие виджетов (по одной записи на администратора).
 */
return new class extends Migration
{
    /**
     * Создаёт таблицу.
     */
    public function up(): void
    {
        Schema::create('fastleo_dashboard_layouts', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id', 36)->unique();
            $table->json('layout');
            $table->timestamps();
        });
    }

    /**
     * Удаляет таблицу.
     */
    public function down(): void
    {
        Schema::dropIfExists('fastleo_dashboard_layouts');
    }
};
