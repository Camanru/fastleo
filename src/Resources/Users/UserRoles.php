<?php

declare(strict_types=1);

namespace Fastleo\Resources\Users;

use Fastleo\Auth\RoleMatrix;
use Fastleo\Fastleo;
use Fastleo\Fields\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Override;

/**
 * Поле «Роли» администратора: роли `spatie/laravel-permission` под guard `fastleo` (список по именам).
 *
 * Роли других guard (`web` сайта) поле не показывает и не трогает: пишется только разница по ролям `fastleo`.
 * Владельца нельзя лишить роли, если он последний: проверка идёт внутри транзакции записи, после изменения
 * (две правки «снять роль друг у друга» одновременно не оставят сайт без владельца). Модель пользователя
 * должна использовать трейт `HasRoles`.
 *
 * ```php
 * UserRoles::make('roles', 'Роли')->multiple();
 * ```
 */
final class UserRoles extends Select
{
    /**
     * Варианты — роли guard `fastleo`: имя → имя.
     *
     * @return array<string, string>
     */
    #[Override]
    public function getOptions(mixed $dependency = null): array
    {
        $names = $this->strings($this->guardRoles($this->model())->orderBy('name')->pluck('name')->all());

        return array_combine($names, $names);
    }

    /**
     * Роли записи под guard `fastleo`.
     *
     * @return list<string>
     */
    #[Override]
    public function hydrate(Model $record): array
    {
        return $this->currentNames($record);
    }

    /**
     * Роли подгружаются списком одним запросом.
     *
     * @return list<string>
     */
    #[Override]
    public function eagerLoad(): array
    {
        return ['roles'];
    }

    /**
     * Роли guard `fastleo` через запятую — для списка и страницы просмотра.
     */
    #[Override]
    public function display(Model $record): ?string
    {
        $loaded = $record->getRelationValue('roles');
        $roles = $loaded instanceof Collection ? $loaded : $this->relation($record)->get();
        $names = $this->strings($roles->where('guard_name', Fastleo::GUARD)->pluck('name')->all());

        return $names === [] ? null : implode(', ', $names);
    }

    /**
     * Колонки `roles` нет: роли пишутся связью после сохранения.
     */
    #[Override]
    public function writesAttribute(): bool
    {
        return false;
    }

    /**
     * Роли пишутся связью в `afterSave()`, а не атрибутом.
     */
    #[Override]
    public function fill(Model $record, mixed $value): void
    {
        //
    }

    /**
     * Привязать выбранные роли и отвязать убранные; роли других guard остаются.
     * Если после этого не осталось ни одного владельца — запись откатывается. Строка роли-владельца блокируется
     * первой (все такие записи идут по очереди), остаток владельцев читается блокирующим чтением: оно видит уже
     * зафиксированные чужие правки даже при `REPEATABLE READ`, и два владельца не снимут роль друг у друга одновременно.
     */
    #[Override]
    public function afterSave(Model $record, mixed $value): void
    {
        $relation = $this->relation($record);
        $owner = UserGuard::lockOwnerRole($record);
        $before = $this->currentNames($record);
        $wanted = $this->guardRoles($record)->whereIn('name', $this->strings(is_array($value) ? $value : []))->pluck($this->keyName($record))->all();
        $present = $this->guardRoles($record)->whereIn('name', $before)->pluck($this->keyName($record))->all();

        $relation->detach(collect($present)->diff($wanted)->values()->all());
        $relation->attach(collect($wanted)->diff($present)->values()->all());
        $record->unsetRelation('roles');

        if (method_exists($record, 'forgetCachedPermissions')) {
            $record->forgetCachedPermissions();
            // Параллельный запрос мог до фиксации записи снова положить в кеш старые роли.
            DB::afterCommit(fn () => $record->forgetCachedPermissions());
        }

        if ($owner instanceof Model && in_array(RoleMatrix::ownerRole(), $before, true) && ! UserGuard::ownerRemains($record, $relation, $owner)) {
            throw ValidationException::withMessages([$this->name => __('fastleo::users.last_owner', [], Fastleo::LOCALE)]);
        }
    }

    /**
     * Имена ролей записи под guard `fastleo`.
     *
     * @return list<string>
     */
    private function currentNames(Model $record): array
    {
        return $this->strings($this->relation($record)->where('guard_name', Fastleo::GUARD)->pluck('name')->all());
    }

    /**
     * Роли guard `fastleo` (запрос по модели ролей, которую знает связь `roles` записи).
     *
     * @return Builder<Model>
     */
    private function guardRoles(Model $record): Builder
    {
        return $this->relation($record)->getRelated()->newQuery()->where('guard_name', Fastleo::GUARD);
    }

    /**
     * Первичный ключ модели ролей.
     */
    private function keyName(Model $record): string
    {
        return $this->relation($record)->getRelated()->getKeyName();
    }

    /**
     * Только строки, без повторов.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_unique(array_filter($values, is_string(...))));
    }

    /**
     * Связь `roles` модели (трейт `HasRoles`).
     *
     * @return MorphToMany<Model, Model, MorphPivot, 'pivot'>
     *
     * @throws LogicException у модели нет трейта `HasRoles`
     */
    private function relation(Model $record): MorphToMany
    {
        $relation = method_exists($record, 'roles') ? $record->roles() : null;

        return $relation instanceof MorphToMany ? $relation : throw new LogicException(__('fastleo::exceptions.roles_trait_missing', ['model' => $record::class], Fastleo::LOCALE));
    }
}
