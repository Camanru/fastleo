<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Импорты из Excel/CSV: кто, в какой раздел, сколько строк создано, обновлено, пропущено и почему.
 */
return new class extends Migration
{
    /**
     * Создаёт таблицу.
     */
    public function up(): void
    {
        Schema::create('fastleo_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id', 36)->nullable()->index();
            $table->string('resource', 100);
            $table->string('file_name');
            $table->string('path');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->json('errors')->nullable();
            $table->json('unknown_columns')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Удаляет таблицу.
     */
    public function down(): void
    {
        Schema::dropIfExists('fastleo_imports');
    }
};
