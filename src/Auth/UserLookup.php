<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use Fastleo\Fastleo;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * Поиск пользователя по email в провайдере guard fastleo.
 */
final class UserLookup
{
    /**
     * Пользователь с точно таким же email (в нижнем регистре) или null.
     *
     * Сортировка БД может не различать «á» и «a» или невидимые символы: вариант
     * адреса нашёл бы того же пользователя. Принимается только точное совпадение.
     *
     * ```php
     * UserLookup::byEmail('admin@site.ru'); // пользователь или null
     * ```
     */
    public static function byEmail(string $email): ?Authenticatable
    {
        $user = Fastleo::guard()->getProvider()->retrieveByCredentials(['email' => $email]);

        if (! $user instanceof Authenticatable) {
            return null;
        }

        return Str::lower(self::emailOf($user)) === $email ? $user : null;
    }

    /**
     * Пользователь с доступом в админку или null.
     */
    public static function adminByEmail(string $email): ?FastleoUser
    {
        $user = self::byEmail($email);

        return $user instanceof FastleoUser && $user->canAccessFastleo() ? $user : null;
    }

    /**
     * Email пользователя из модели.
     */
    public static function emailOf(Authenticatable $user): string
    {
        $email = method_exists($user, 'getAttribute') ? $user->getAttribute('email') : null;

        return is_string($email) ? $email : '';
    }
}
