<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use Fastleo\Fastleo;
use Fastleo\Resources\RoleResource;
use Fastleo\Resources\UserResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;

/**
 * Права роли по разделам: какие разделы и группы есть в матрице, какие права у роли и как записать их роли.
 *
 * В матрице — разделы-ресурсы сайта, кроме «Администраторов» и «Ролей»: ими управляет только владелец. У раздела
 * «только для чтения» одна группа — просмотр. Записываются только права матрицы (`fastleo.{раздел}.{группа}`):
 * остальные права роли, которые завёл сайт, остаются нетронутыми.
 *
 * ```php
 * RolePermissions::sync($role, ['fastleo.articles.view', 'fastleo.articles.update']); // view добавится сам
 * ```
 */
final class RolePermissions
{
    /** Группы матрицы по порядку столбцов. */
    public const array GROUPS = ['view', 'create', 'update', 'delete'];

    /**
     * Разделы матрицы: адрес, название и группы, которые в них бывают.
     *
     * @return list<array{slug: string, label: string, groups: list<string>}>
     */
    public static function sections(): array
    {
        $sections = [];

        foreach (Fastleo::resources()->all() as $slug => $class) {
            if (is_a($class, UserResource::class, true) || is_a($class, RoleResource::class, true)) {
                continue;
            }

            $sections[] = ['slug' => $slug, 'label' => $class::label(), 'groups' => $class::isReadonly() ? ['view'] : self::GROUPS];
        }

        return $sections;
    }

    /**
     * Все права матрицы: `fastleo.articles.view`, `fastleo.articles.create`…
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [];

        foreach (self::sections() as $section) {
            foreach ($section['groups'] as $group) {
                $names[] = RoleMatrix::permission($section['slug'], $group);
            }
        }

        return $names;
    }

    /**
     * Права матрицы, которые есть у роли.
     *
     * @return list<string>
     */
    public static function current(Model $role): array
    {
        $relation = self::relation($role);

        if (! $relation instanceof BelongsToMany || ! $role->exists) {
            return [];
        }

        $granted = $relation->where('guard_name', Fastleo::GUARD)->pluck('name')->all();

        return array_values(array_filter(self::names(), fn (string $name): bool => in_array($name, $granted, true)));
    }

    /**
     * Выбранные права без повторов и посторонних имён; любая группа раздела тянет за собой просмотр.
     *
     * @param  array<array-key, mixed>  $wanted
     * @return list<string>
     */
    public static function normalize(array $wanted): array
    {
        $names = self::names();
        $chosen = array_values(array_intersect($names, array_filter($wanted, is_string(...))));

        return array_values(array_filter($names, fn (string $name): bool => in_array($name, $chosen, true)
            || (str_ends_with($name, '.view') && array_any($chosen, fn (string $other): bool => str_starts_with($other, substr($name, 0, -4))))));
    }

    /**
     * Записать выбранные права матрицы: недостающие создаются и выдаются роли, снятые отбираются.
     *
     * @param  array<array-key, mixed>  $wanted
     */
    public static function sync(Model $role, array $wanted): void
    {
        $relation = self::relation($role);

        if (! $relation instanceof BelongsToMany) {
            return;
        }

        $wanted = self::normalize($wanted);
        $current = self::current($role);
        $related = $relation->getRelated();
        $ids = [];

        foreach (array_diff($wanted, $current) as $name) {
            $ids[] = $related->newQuery()->firstOrCreate(['name' => $name, 'guard_name' => Fastleo::GUARD])->getKey();
        }

        $relation->attach($ids);
        $relation->detach($related->newQuery()->where('guard_name', Fastleo::GUARD)->whereIn('name', array_diff($current, $wanted))->pluck($related->getKeyName())->all());

        if (method_exists($role, 'forgetCachedPermissions')) {
            $role->forgetCachedPermissions();
            // Параллельный запрос мог до фиксации записи снова положить в кеш старые права.
            DB::afterCommit(fn () => $role->forgetCachedPermissions());
        }
    }

    /**
     * Связь `permissions` модели роли (трейт spatie) или `null`.
     *
     * @return BelongsToMany<Model, Model, Pivot, 'pivot'>|null
     */
    private static function relation(Model $role): ?BelongsToMany
    {
        $relation = method_exists($role, 'permissions') ? $role->permissions() : null;

        return $relation instanceof BelongsToMany ? $relation : null;
    }
}
