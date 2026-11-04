<?php

declare(strict_types=1);

namespace Fastleo\Resources;

use Fastleo\Auth\RoleMatrix;
use Fastleo\Fastleo;
use Fastleo\Fields\Password;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Resources\Users\AdminAccess;
use Fastleo\Resources\Users\AdminEmail;
use Fastleo\Resources\Users\ResetTwoFactor;
use Fastleo\Resources\Users\UserGuard;
use Fastleo\Resources\Users\UserRoles;
use Fastleo\Support\OperationCancelled;
use Fastleo\Support\UserModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Override;

/**
 * Встроенный раздел «Администраторы»: пользователи сайта с доступом в админку.
 *
 * Работает на модели пользователей guard fastleo. Показывает только администраторов (`fastleo_admin`);
 * нельзя удалить себя и последнего администратора, снять доступ у себя и у последнего. При включённых ролях
 * раздел открыт только владельцу, в форме появляется поле «Роли» и нельзя оставить сайт без владельца. Свой пароль
 * меняется в профиле. Включается ключом `fastleo.users.resource`: класс этого ресурса (по умолчанию),
 * ваш наследник или `null`, чтобы раздела не было.
 *
 * ```php
 * // app/Fastleo/AdminResource.php — свой раздел поверх встроенного
 * final class AdminResource extends \Fastleo\Resources\UserResource
 * {
 *     protected static ?string $group = 'Настройки';
 * }
 * // config/fastleo.php: 'users' => ['resource' => App\Fastleo\AdminResource::class]
 * ```
 */
class UserResource extends Resource
{
    protected static ?string $slug = 'users';

    /** Администраторы — почта, пароли и доступы: ИИ-агенту раздел не виден. */
    protected static bool $mcp = false;

    protected static string $icon = 'shield-check';

    protected static int $position = 100;

    protected static ?array $defaultOrder = ['name', 'asc'];

    /**
     * Модель пользователей guard fastleo.
     *
     * @return class-string<Model>
     *
     * @throws LogicException модель не подходит для админки
     */
    #[Override]
    public static function model(): string
    {
        return UserModel::fastleoClassName() ?? throw new LogicException(__('fastleo::console.user_model_invalid', ['model' => UserModel::className() ?? '—']));
    }

    #[Override]
    public static function hasModel(): bool
    {
        return UserModel::fastleoClassName() !== null;
    }

    #[Override]
    public static function label(): string
    {
        return static::$label ?? __('fastleo::users.label', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function description(): ?string
    {
        return static::$description ?? __('fastleo::users.description', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function singular(): string
    {
        return static::$singular ?? __('fastleo::users.singular', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function group(): ?string
    {
        return static::$group ?? __('fastleo::users.group', [], Fastleo::LOCALE);
    }

    /**
     * Имя, email, пароль и доступ в админку.
     */
    #[Override]
    public function fields(): array
    {
        return [
            Text::make('name', __('fastleo::users.name'))->required()->maxLength(255)->searchable()->sortable(),
            AdminEmail::make('email', __('fastleo::users.email'))->required()->maxLength(255)->searchable()->sortable(),
            Password::make('password', __('fastleo::users.password'))->required()->confirmed()
                ->help(__('fastleo::users.password_help'))
                ->hidden(fn (?Model $record): bool => $record instanceof Model && UserGuard::isCurrentUser($record)),
            AdminAccess::make('fastleo_admin', __('fastleo::users.access'))->default(true),
            ...(RoleMatrix::enabled() ? [UserRoles::make('roles', __('fastleo::users.roles'))->multiple()->help(__('fastleo::users.roles_help'))] : []),
        ];
    }

    /**
     * Только администраторы.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    #[Override]
    public function query(Builder $query): Builder
    {
        return $query->where('fastleo_admin', true);
    }

    #[Override]
    public function actions(): array
    {
        return [new ResetTwoFactor()];
    }

    /**
     * Нельзя удалить последнего владельца: проверка внутри транзакции удаления, с блокировкой (до того, как spatie снимет роли
     * с удаляемой записи), поэтому два владельца, удаляющие друг друга одновременно, не оставят сайт без владельца.
     *
     * @throws OperationCancelled удаляется последний владелец
     */
    #[Override]
    public function beforeDelete(Model $record): void
    {
        if (! UserGuard::mayDelete($record)) {
            throw new OperationCancelled(__('fastleo::users.last_owner_delete', [], Fastleo::LOCALE));
        }
    }

    /**
     * При включённых ролях раздел — только владельцу (иначе право на «Администраторов» отдаёт чужие пароли и роли).
     * Себя и последнего администратора удалить нельзя.
     */
    #[Override]
    public function can(string $ability, ?Model $record): bool
    {
        if (! RoleMatrix::allowsOwnerOnly()) {
            return false;
        }

        if (! $record instanceof Model || ! $record->exists || ! in_array($ability, ['delete', 'forceDelete'], true)) {
            return true;
        }

        return ! UserGuard::isCurrentUser($record) && ! UserGuard::isLastAdmin($record);
    }
}
