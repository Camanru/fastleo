<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал изменений: кто, когда и что изменил в разделах админки, «было → стало», копия удалённой записи.
 */
return new class extends Migration
{
    /**
     * Создаёт таблицу.
     */
    public function up(): void
    {
        Schema::create('fastleo_activity', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id', 36)->nullable()->index();
            $table->string('user_name')->nullable();
            $table->string('resource', 100);
            $table->string('subject_id', 36)->nullable();
            $table->string('subject_label')->nullable();
            $table->string('event', 20);
            $table->json('changes')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['resource', 'subject_id']);
        });
    }

    /**
     * Удаляет таблицу.
     */
    public function down(): void
    {
        Schema::dropIfExists('fastleo_activity');
    }
};
