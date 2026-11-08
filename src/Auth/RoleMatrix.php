<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use Fastleo\Fastleo;
use Fastleo\Resource;
use Fastleo\Support\UserModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Gate;

/**
 * Матрица ролей администратора поверх Policy: «раздел × Просмотр/Создание/Изменение/Удаление».
 *
 * Работает через `spatie/laravel-permission` и только при `fastleo.roles.enabled`. Права и роли — под guard
 * `fastleo`: право называется `fastleo.{slug}.{группа}` (`fastleo.articles.update`), роль-владелец
 * (`fastleo.roles.owner`) проходит всё. Любая группа требует ещё и `view`. Включено, но у модели пользователя нет
 * трейта `HasRoles` — запрет, а не «разрешено всё». Классы spatie здесь не используются: пользователь опрашивается
 * по методам, поэтому пакет остаётся необязательным.
 *
 * ```php
 * RoleMatrix::allows('update', 'articles'); // право fastleo.articles.update у вошедшего администратора
 * ```
 */
final class RoleMatrix
{
    /** Способность Policy → группа матрицы; способность, которой здесь нет (своё `$ability` у Action), считается изменением. */
    public const array GROUPS = [
        'viewAny' => 'view',
        'view' => 'view',
        'export' => 'view',
        'create' => 'create',
        'import' => 'create',
        'update' => 'update',
        'reorder' => 'update',
        'restore' => 'update',
        'delete' => 'delete',
        'forceDelete' => 'delete',
    ];

    /** Роль-владелец по умолчанию. */
    public const string DEFAULT_OWNER_ROLE = 'fastleo-owner';

    /**
     * Включены ли роли (`fastleo.roles.enabled`).
     */
    public static function enabled(): bool
    {
        return config()->boolean('fastleo.roles.enabled', false);
    }

    /**
     * Имя роли-владельца (`fastleo.roles.owner`).
     */
    public static function ownerRole(): string
    {
        return config()->string('fastleo.roles.owner', self::DEFAULT_OWNER_ROLE);
    }

    /**
     * Имя права: `fastleo.articles.update`.
     */
    public static function permission(string $slug, string $group): string
    {
        return "fastleo.{$slug}.{$group}";
    }

    /**
     * Разрешает ли матрица способность в разделе. Роли выключены — всегда да.
     *
     * ```php
     * RoleMatrix::allows('delete', 'articles');
     * ```
     */
    public static function allows(string $ability, string $slug): bool
    {
        if (! self::enabled()) {
            return true;
        }

        $user = self::roleUser();

        if ($user === null) {
            return false;
        }

        if (self::hasOwnerRole($user)) {
            return true;
        }

        $group = self::GROUPS[$ability] ?? 'update';

        return self::hasPermission($user, $slug, 'view') && ($group === 'view' || self::hasPermission($user, $slug, $group));
    }

    /**
     * Вошедший администратор — владелец. Роли выключены — нет.
     */
    public static function isOwner(): bool
    {
        $user = self::enabled() ? self::roleUser() : null;

        return $user !== null && self::hasOwnerRole($user);
    }

    /**
     * Для страниц и действий вне разделов-ресурсов (настройки, файлы, журнал ошибок…): роли выключены — разрешено,
     * включены — только владельцу.
     */
    public static function allowsOwnerOnly(): bool
    {
        return ! self::enabled() || self::isOwner();
    }

    /**
     * Модель ролей, которую знает модель пользователей (связь `roles` трейта `HasRoles`); `null` — трейта нет.
     */
    public static function roleModel(): ?Model
    {
        $class = UserModel::fastleoClassName();
        $user = $class === null ? null : new $class();
        $relation = $user !== null && method_exists($user, 'roles') ? $user->roles() : null;

        return $relation instanceof MorphToMany ? $relation->getRelated() : null;
    }

    /**
     * Можно ли показывать администратору подписи записей связанной модели (варианты выбора): у модели есть разделы —
     * нужен просмотр хотя бы одного из них по матрице и `Resource::can()` (закрытый раздел «Администраторы» подписей
     * не отдаёт), иначе перебором `options` читается чужая таблица. Нет разделов — можно. Policy здесь не спрашивается, как и раньше:
     * связь доступна тому, кто может править поле.
     *
     * ```php
     * RoleMatrix::allowsRelated($field->relatedModel());
     * ```
     */
    public static function allowsRelated(Model $related): bool
    {
        if (! self::enabled()) {
            return true;
        }

        // У модели может быть несколько разделов («Статьи» и «Новости»): подписи записей открыты, если администратору
        // открыт хотя бы один — иначе закрытый первый по алфавиту закрыл бы связь, которую второй раздел разрешает.
        $resources = Fastleo::resources()->allForModel($related::class);

        return $resources === [] || array_any($resources, fn (Resource $resource): bool => self::allows('viewAny', $resource::slug()) && $resource->can('viewAny', null));
    }

    /**
     * Связь на раздел, который администратору не открыт (`allowsRelated()`), нужно закрыть: не встраивать в страницу
     * варианты выбора и не принимать новые значения. Без вошедшего администратора (консоль, `inspect`) и при выключенных
     * ролях — нет.
     *
     * ```php
     * RoleMatrix::closesRelated($field->relatedModel());
     * ```
     */
    public static function closesRelated(Model $related): bool
    {
        return self::enabled() && Fastleo::guard()->user() !== null && ! self::allowsRelated($related);
    }

    /**
     * Решение Gate сайта (`viewFastleoLogs`, `clearFastleoCache`, `manageFastleoFiles`, `manageFastleoSoftNetica`),
     * а если Gate не описан — владелец при включённых ролях и любой администратор при выключенных.
     *
     * ```php
     * RoleMatrix::gate('clearFastleoCache');
     * ```
     */
    public static function gate(string $ability): bool
    {
        return Gate::has($ability)
            ? Gate::forUser(Fastleo::guard()->user())->allows($ability)
            : self::allowsOwnerOnly();
    }

    /**
     * Администратор может что-то менять: роли выключены, он владелец или у него есть создание или изменение в каком-либо разделе.
     * От этого зависит загрузка файлов: «Наблюдателю» она не нужна.
     */
    public static function canWrite(): bool
    {
        if (! self::enabled()) {
            return true;
        }

        $user = self::roleUser();

        if ($user === null) {
            return false;
        }

        if (self::hasOwnerRole($user)) {
            return true;
        }

        return array_any(array_keys(Fastleo::resources()->all()), fn (string $slug): bool => self::hasPermission($user, $slug, 'view') && (self::hasPermission($user, $slug, 'create') || self::hasPermission($user, $slug, 'update')));
    }

    /**
     * Вошедший администратор, если у модели есть методы spatie (`HasRoles`); иначе `null` — матрица запрещает всё.
     */
    private static function roleUser(): ?object
    {
        $user = Fastleo::guard()->user();

        return $user !== null && method_exists($user, 'hasRole') && method_exists($user, 'checkPermissionTo') ? $user : null;
    }

    /**
     * Роль-владелец именно guard `fastleo`: одноимённая роль сайта (`web`) владельцем не делает.
     */
    private static function hasOwnerRole(object $user): bool
    {
        return method_exists($user, 'hasRole') && $user->hasRole(self::ownerRole(), Fastleo::GUARD) === true;
    }

    /**
     * Право guard `fastleo` у пользователя (прямое или через роль). Права нет в таблице — `false`, а не исключение.
     */
    private static function hasPermission(object $user, string $slug, string $group): bool
    {
        return method_exists($user, 'checkPermissionTo')
            && $user->checkPermissionTo(self::permission($slug, $group), Fastleo::GUARD) === true;
    }
}
