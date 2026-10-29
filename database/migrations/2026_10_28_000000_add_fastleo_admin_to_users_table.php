<?php

declare(strict_types=1);

use Fastleo\Support\UserModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Колонка fastleo_admin в таблице пользователей — флаг доступа в админку (трейт HasFastleoAccess).
 *
 * Таблица берётся из модели провайдера guard fastleo.
 */
return new class extends Migration
{
    /**
     * Добавляет колонку.
     */
    public function up(): void
    {
        Schema::table($this->usersTable(), function (Blueprint $table): void {
            $table->boolean('fastleo_admin')->default(false)->index();
        });
    }

    /**
     * Удаляет колонку.
     */
    public function down(): void
    {
        Schema::table($this->usersTable(), function (Blueprint $table): void {
            $table->dropIndex(['fastleo_admin']);
            $table->dropColumn('fastleo_admin');
        });
    }

    /**
     * Таблица модели пользователей guard fastleo, по умолчанию users.
     */
    private function usersTable(): string
    {
        $model = UserModel::className();

        return $model !== null && is_a($model, Model::class, true) ? (new $model)->getTable() : 'users';
    }
};
