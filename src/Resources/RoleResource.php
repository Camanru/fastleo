<?php

declare(strict_types=1);

namespace Fastleo\Resources;

use Fastleo\Auth\RoleMatrix;
use Fastleo\Fastleo;
use Fastleo\Resource;
use Fastleo\Resources\Roles\PermissionMatrix;
use Fastleo\Resources\Roles\RoleName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Override;

/**
 * Встроенный раздел «Роли»: роли администраторов и матрица прав «раздел × просмотр/создание/изменение/удаление».
 *
 * Появляется при `fastleo.roles.enabled` и виден только владельцу. Показывает роли guard `fastleo`; роль-владелец
 * можно только смотреть, менять и удалять её нельзя. Роль выдаётся администратору в разделе «Администраторы».
 * Подключается ключом `fastleo.roles.resource`: класс этого ресурса (по умолчанию), ваш наследник или `null`.
 *
 * ```php
 * // app/Fastleo/RoleResource.php — свой раздел поверх встроенного
 * final class RoleResource extends \Fastleo\Resources\RoleResource
 * {
 *     protected static ?string $group = 'Настройки';
 * }
 * // config/fastleo.php: 'roles' => ['resource' => App\Fastleo\RoleResource::class]
 * ```
 */
class RoleResource extends Resource
{
    protected static ?string $slug = 'roles';

    /** Роли и права — ИИ-агенту раздел не виден. */
    protected static bool $mcp = false;

    protected static bool $exportable = false;

    protected static bool $globallySearchable = false;

    protected static string $icon = 'key-round';

    protected static int $position = 101;

    protected static ?array $defaultOrder = ['name', 'asc'];

    /**
     * Модель ролей, которую знает модель пользователей (связь `roles` трейта `HasRoles`).
     *
     * @return class-string<Model>
     *
     * @throws LogicException у модели пользователей нет трейта `HasRoles`
     */
    #[Override]
    public static function model(): string
    {
        $roles = RoleMatrix::roleModel();

        return $roles instanceof Model ? $roles::class : throw new LogicException(__('fastleo::exceptions.roles_trait_missing', ['model' => 'users'], Fastleo::LOCALE));
    }

    #[Override]
    public static function hasModel(): bool
    {
        return RoleMatrix::roleModel() instanceof Model;
    }

    #[Override]
    public static function label(): string
    {
        return static::$label ?? __('fastleo::roles.label', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function description(): ?string
    {
        return static::$description ?? __('fastleo::roles.description', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function singular(): string
    {
        return static::$singular ?? __('fastleo::roles.singular', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function group(): ?string
    {
        return static::$group ?? __('fastleo::users.group', [], Fastleo::LOCALE);
    }

    /**
     * Название роли и матрица прав.
     */
    #[Override]
    public function fields(): array
    {
        return [
            RoleName::make('name', __('fastleo::roles.name', [], Fastleo::LOCALE))->required()->maxLength(125)->searchable()->sortable(),
            PermissionMatrix::make('permissions', __('fastleo::roles.permissions', [], Fastleo::LOCALE))->help(__('fastleo::roles.permissions_help', [], Fastleo::LOCALE))->hideInList(),
        ];
    }

    /**
     * Только роли guard `fastleo`: роли `web` сайта раздел не видит и не меняет.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    #[Override]
    public function query(Builder $query): Builder
    {
        return $query->where('guard_name', Fastleo::GUARD);
    }

    /**
     * Раздел — только владельцу; роль-владелец только смотрят.
     */
    #[Override]
    public function can(string $ability, ?Model $record): bool
    {
        if (! RoleMatrix::allowsOwnerOnly()) {
            return false;
        }

        $isOwnerRole = $record instanceof Model && $record->exists && $record->getAttribute('name') === RoleMatrix::ownerRole();

        return ! $isOwnerRole || ! in_array($ability, ['update', 'delete', 'forceDelete'], true);
    }
}
