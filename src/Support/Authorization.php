<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Fastleo;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Права администратора на действия в разделе — через Policy модели сайта.
 *
 * Нет Policy у модели — разрешено всё. Есть Policy — решает её метод (`viewAny`, `view`, `create`,
 * `update`, `delete`, `restore`, `forceDelete`); метода нет — запрещено, как везде в Laravel.
 * В разделе «только чтение» изменения запрещены всегда. Метод ресурса `can()` может запретить действие ещё и сверх Policy.
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
     * Разрешено ли действие текущему администратору.
     */
    public static function allows(string $ability, Resource $resource, ?Model $record = null): bool
    {
        if ($resource::isReadonly() && in_array($ability, self::WRITE_ABILITIES, true)) {
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
        if (! $resource->can($ability, $record)) {
            return false;
        }

        $policy = Gate::getPolicyFor($resource::model());

        if (is_object($policy) && method_exists($policy, $ability)) {
            return Gate::forUser(Fastleo::guard()->user())->allows($ability, $record ?? $resource::model());
        }

        return self::allows($fallback, $resource, $record);
    }
}
