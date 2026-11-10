<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заявки (раздел «Заявки»): с формы сайта и с других сайтов по API; страница, с которой пришла заявка, сайт и форма, статус.
 */
return new class extends Migration
{
    /**
     * Создаёт таблицу.
     */
    public function up(): void
    {
        Schema::create('fastleo_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('phone', 50);
            $table->string('email')->nullable();
            $table->text('message')->nullable();
            $table->string('source', 500)->nullable();
            $table->string('site', 100)->nullable();
            $table->string('form', 100)->nullable();
            $table->string('status', 20)->default('new')->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Удаляет таблицу.
     */
    public function down(): void
    {
        Schema::dropIfExists('fastleo_leads');
    }
};
