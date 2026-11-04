<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Заметки редакции: запись любого раздела (статья или товар) через полиморфную связь.
     */
    public function up(): void
    {
        Schema::create('remarks', function (Blueprint $table): void {
            $table->id();
            $table->text('body');
            $table->nullableMorphs('subject');
            $table->timestamps();
        });
    }

    /**
     * Удалить таблицу заметок.
     */
    public function down(): void
    {
        Schema::dropIfExists('remarks');
    }
};
