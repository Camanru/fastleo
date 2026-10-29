<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use Illuminate\Database\Eloquent\Model;

/**
 * Доступ в админку по булевой колонке fastleo_admin таблицы пользователей.
 *
 * Колонку добавляет миграция пакета, флаг ставит `php artisan fastleo:user`.
 * Не добавляйте fastleo_admin в $fillable модели: иначе форма регистрации
 * сайта сможет выдать права администратора.
 *
 * ```php
 * class User extends Authenticatable implements FastleoUser
 * {
 *     use HasFastleoAccess;
 * }
 * ```
 *
 * @mixin Model
 */
trait HasFastleoAccess
{
    /**
     * Пользователь — администратор fastleo, если в колонке fastleo_admin истина.
     *
     * ```php
     * $user->canAccessFastleo(); // true для fastleo_admin = 1
     * ```
     */
    public function canAccessFastleo(): bool
    {
        return (bool) $this->getAttribute('fastleo_admin');
    }
}
