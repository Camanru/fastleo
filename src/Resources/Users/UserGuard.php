<?php

declare(strict_types=1);

namespace Fastleo\Resources\Users;

use Fastleo\Auth\RoleMatrix;
use Fastleo\Fastleo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

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

    /**
     * Блокирует строку роли-владельца: изменения состава владельцев идут по очереди. Возвращает роль или `null`, если
     * у модели нет трейта `HasRoles` или роли ещё нет.
     */
    public static function lockOwnerRole(Model $record): ?Model
    {
        $relation = self::rolesRelation($record);
        $role = $relation?->getRelated()->newQuery()->where('name', RoleMatrix::ownerRole())->where('guard_name', Fastleo::GUARD)->lockForUpdate()->first();

        return $role instanceof Model ? $role : null;
    }

    /**
     * Остался ли хоть один владелец с доступом в админку. Читает блокирующими запросами: обычный `SELECT` при
     * `REPEATABLE READ` видит старый снимок и пропустил бы чужую, уже зафиксированную правку. `$withoutRecord` — не считать
     * саму запись (её роль ещё не снята, а запись удаляется).
     *
     * @param  MorphToMany<Model, Model, MorphPivot, 'pivot'>  $relation  связь `roles` записи
     */
    public static function ownerRemains(Model $record, MorphToMany $relation, Model $ownerRole, bool $withoutRecord = false): bool
    {
        $ids = $record->getConnection()->table($relation->getTable())
            ->where($relation->getRelatedPivotKeyName(), $ownerRole->getKey())
            ->where($relation->getMorphType(), $relation->getMorphClass())
            ->lockForUpdate()
            ->pluck($relation->getForeignPivotKeyName())
            ->all();

        if ($withoutRecord) {
            $ids = collect($ids)->diff([$record->getKey()])->values()->all();
        }

        return $ids !== [] && $record->newQuery()->whereIn($record->getKeyName(), $ids)->where('fastleo_admin', true)->lockForUpdate()->exists();
    }

    /**
     * Удаление записи не оставит сайт без доступа: запись не администратор (роли выключены) либо другой администратор или владелец остаётся.
     * Вызывается из события `deleting` модели пользователей, внутри транзакции удаления.
     */
    public static function mayDelete(Model $record): bool
    {
        if (! RoleMatrix::enabled()) {
            // Без ролей: другой администратор должен остаться; блокировка не даёт двум последним удалить друг друга одновременно.
            return ! $record->getAttribute('fastleo_admin')
                || $record->newQuery()->whereKeyNot($record->getKey())->where('fastleo_admin', true)->lockForUpdate()->exists();
        }

        $owner = self::lockOwnerRole($record);
        $relation = self::rolesRelation($record);

        if (! $owner instanceof Model || ! $relation instanceof MorphToMany || ! self::holds($record, $owner)) {
            return true;
        }

        return self::ownerRemains($record, $relation, $owner, true);
    }

    /**
     * У записи есть эта роль.
     */
    public static function holds(Model $record, Model $role): bool
    {
        return self::rolesRelation($record)?->whereKey($role->getKey())->exists() === true;
    }

    /**
     * Связь `roles` модели (трейт `HasRoles`) или `null`.
     *
     * @return MorphToMany<Model, Model, MorphPivot, 'pivot'>|null
     */
    public static function rolesRelation(Model $record): ?MorphToMany
    {
        $relation = method_exists($record, 'roles') ? $record->roles() : null;

        return $relation instanceof MorphToMany ? $relation : null;
    }

    /**
     * Владельцы с доступом в админку: роль-владелец guard `fastleo` и `fastleo_admin` (запрос по модели записи).
     *
     * @return Builder<Model>
     */
    public static function owners(Model $record): Builder
    {
        return $record->newQuery()
            ->where('fastleo_admin', true)
            ->whereHas('roles', fn (Builder $roles) => $roles->where('name', RoleMatrix::ownerRole())->where('guard_name', Fastleo::GUARD));
    }
}
