<?php

declare(strict_types=1);

namespace Fastleo\Auth;

/**
 * Пользователь, который может входить в админку.
 *
 * Модель пользователя сайта реализует этот интерфейс. Готовая реализация —
 * трейт {@see HasFastleoAccess} (доступ по колонке fastleo_admin).
 *
 * ```php
 * class User extends Authenticatable implements FastleoUser
 * {
 *     use HasFastleoAccess;
 * }
 * ```
 */
interface FastleoUser
{
    /**
     * Можно ли пользователю входить в админку. Проверяется при входе и на каждом запросе.
     *
     * ```php
     * public function canAccessFastleo(): bool
     * {
     *     return $this->role === 'admin';
     * }
     * ```
     */
    public function canAccessFastleo(): bool;
}
