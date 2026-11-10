<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Auth\RoleMatrix;
use Fastleo\Fastleo;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Права пользователя на действия в разделе — через Policy модели сайта.
 *
 * Нет Policy у модели — разрешено всё. Есть Policy — решает её метод (`viewAny`, `view`, `create`,
 * `update`, `delete`, `restore`, `forceDelete`); метода нет — запрещено, как везде в Laravel.
 * В разделе «только чтение» изменения запрещены всегда. Метод ресурса `can()` может запретить действие ещё и сверх Policy.
 * При включённых ролях (`fastleo.roles.enabled`) сначала спрашивается матрица ролей ({@see RoleMatrix}): запрет любого
 * из трёх слоёв — запрет.
 *
 * ```php
 * Authorization::allows('update', $resource, $record);
 * ```
 */
final class Authorization
{
    /** Действия, которые меняют данные. */
    public const array WRITE_ABILITIES = ['create', 'update', 'delete', 'restore', 'forceDelete'];

    /**
     * Разрешено ли действие текущему пользователю.
     */
    public static function allows(string $ability, Resource $resource, ?Model $record = null): bool
    {
        if ($resource::isReadonly() && in_array($ability, self::WRITE_ABILITIES, true)) {
            return false;
        }

        if (self::deniedToNonOwner($ability, $resource) || ! RoleMatrix::allows($ability, $resource::slug())) {
            return false;
        }

        if (! $resource->can($ability, $record)) {
            return false;
        }

        if (Gate::getPolicyFor($resource::model()) === null) {
            return true;
        }

        return Gate::forUser(Fastleo::guard()->user())->allows($ability, $record ?? $resource::model());
    }

    /**
     * Остановить запрос с 403, если действие запрещено.
     */
    public static function authorize(string $ability, Resource $resource, ?Model $record = null): void
    {
        abort_unless(self::allows($ability, $resource, $record), 403);
    }

    /**
     * Можно менять порядок записи (стрелки, перетаскивание): метод `reorder` Policy, если он есть, иначе `update`.
     * Раздел только для просмотра — нельзя.
     *
     * ```php
     * Authorization::allowsReorder($resource, $record);
     * ```
     */
    public static function allowsReorder(Resource $resource, Model $record): bool
    {
        return ! $resource::isReadonly() && self::allowsOwnOr('reorder', 'update', $resource, $record);
    }

    /**
     * Своя способность Policy, если у Policy есть такой метод, иначе запасная.
     * Метод ресурса `can()` спрашивается про свою способность в обоих случаях: запрет `export` сработает и без Policy.
     * Нет Policy — разрешено (запасная способность проходит и проверку «только просмотр»).
     *
     * ```php
     * Authorization::allowsOwnOr('export', 'viewAny', $resource); // метод export() Policy или viewAny()
     * ```
     */
    public static function allowsOwnOr(string $ability, string $fallback, Resource $resource, ?Model $record = null): bool
    {
        if (self::deniedToNonOwner($ability, $resource) || ! RoleMatrix::allows($ability, $resource::slug()) || ! $resource->can($ability, $record)) {
            return false;
        }

        $policy = Gate::getPolicyFor($resource::model());

        if (is_object($policy) && method_exists($policy, $ability)) {
            return Gate::forUser(Fastleo::guard()->user())->allows($ability, $record ?? $resource::model());
        }

        return self::allows($fallback, $resource, $record);
    }

    /**
     * Способность закрыта разделом для всех, кроме владельца (`$ownerOnly`, `$ownerOnlyIndex`).
     *
     * В `$ownerOnly` — группы (`view`, `create`, `update`, `delete`: по `RoleMatrix::GROUPS`, неизвестная способность Action
     * считается изменением), точные способности (`export`, `import`, `forceDelete`) или `'*'` — раздел целиком. Владельца
     * нет вовсе (ни ролей, ни `Fastleo::resolveOwnerUsing()`) — закрыто всем, а `fastleo:doctor` говорит об этом ошибкой.
     * Общий список (`$ownerOnlyIndex`) закрывается только на странице списка этого раздела, а не в связанных таблицах.
     *
     * ```php
     * Authorization::deniedToNonOwner('delete', $resource);
     * ```
     */
    public static function deniedToNonOwner(string $ability, Resource $resource): bool
    {
        $closed = $resource::ownerOnlyGroups();
        $indexOnly = $ability === 'viewAny' && $resource::ownerOnlyIndexPage() && self::isIndexSurfaceOf($resource);

        if ($closed === [] && ! $indexOnly) {
            return false;
        }

        if (Fastleo::isOwner()) {
            return false;
        }

        $group = RoleMatrix::GROUPS[$ability] ?? 'update';

        return $indexOnly || in_array('*', $closed, true) || in_array($ability, $closed, true) || in_array($group, $closed, true);
    }

    /**
     * Запрос идёт не из связанной таблицы на карточке и не из меню этого раздела: сам общий список, его выгрузка, импорт и
     * фильтры, а также всё вне разделов-ресурсов (поиск Ctrl+K, дашборд, консоль, MCP, очередь). Закрыто по умолчанию:
     * открыты только маршруты разделов-ресурсов, кроме перечисленных страниц самого раздела.
     */
    private static function isIndexSurfaceOf(Resource $resource): bool
    {
        $request = request();

        if (! $request->routeIs('fastleo.resources.*')) {
            return true;
        }

        return $request->route('resource') === $resource::slug()
            && $request->routeIs('fastleo.resources.index', 'fastleo.resources.export', 'fastleo.resources.import', 'fastleo.resources.imports.*', 'fastleo.resources.filter-options');
    }

    /**
     * Раздел можно показать ссылкой (меню, главная, поиск): право `viewAny` есть и общий список не закрыт `$ownerOnlyIndex`.
     *
     * ```php
     * Authorization::allowsIndexLink($resource);
     * ```
     */
    public static function allowsIndexLink(Resource $resource): bool
    {
        return (! $resource::ownerOnlyIndexPage() || Fastleo::isOwner()) && self::allows('viewAny', $resource);
    }
}
