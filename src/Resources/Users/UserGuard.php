<?php

declare(strict_types=1);

namespace Fastleo\Resources\Users;

use Fastleo\Fastleo;
use Illuminate\Database\Eloquent\Model;

/**
 * Защита раздела администраторов: нельзя лишить доступа себя и последнего администратора.
 */
final class UserGuard
{
    /**
     * Запись — вошедший администратор.
     */
    public static function isCurrentUser(Model $record): bool
    {
        $current = Fastleo::guard()->user();

        return $current instanceof Model && $current->is($record);
    }

    /**
     * Запись — единственный оставшийся администратор.
     */
    public static function isLastAdmin(Model $record): bool
    {
        return (bool) $record->getAttribute('fastleo_admin')
            && ! $record->newQuery()->whereKeyNot($record->getKey())->where('fastleo_admin', true)->exists();
    }
}
