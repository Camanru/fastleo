<?php

declare(strict_types=1);

namespace Fastleo\Resources\Roles;

use Fastleo\Auth\RolePermissions;
use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Override;

/**
 * Матрица прав роли: строки — разделы сайта, столбцы — «Просмотр», «Создание», «Изменение», «Удаление».
 *
 * Право на создание, изменение или удаление требует просмотра: галочка ставит его сама, снятие просмотра убирает
 * остальные. Хранится правами `spatie/laravel-permission` (`fastleo.articles.update`) под guard `fastleo`; права,
 * которых нет в матрице, не трогаются.
 *
 * ```php
 * PermissionMatrix::make('permissions', 'Права по разделам');
 * ```
 */
final class PermissionMatrix extends Field
{
    /**
     * Разделы матрицы.
     *
     * @return list<array{slug: string, label: string, groups: list<string>}>
     */
    public function sections(): array
    {
        return RolePermissions::sections();
    }

    /**
     * Права роли списком имён.
     *
     * @return list<string>
     */
    #[Override]
    public function hydrate(Model $record): array
    {
        return RolePermissions::current($record);
    }

    /**
     * Колонки `permissions` нет: права пишутся связью после сохранения.
     */
    #[Override]
    public function writesAttribute(): bool
    {
        return false;
    }

    /**
     * Права записываются связью в `afterSave()`, а не атрибутом.
     */
    #[Override]
    public function fill(Model $record, mixed $value): void
    {
        //
    }

    /**
     * Выбранные права без повторов; любая группа раздела тянет за собой просмотр.
     *
     * @return list<string>
     */
    #[Override]
    public function dehydrate(mixed $value): array
    {
        return RolePermissions::normalize(is_array($value) ? $value : []);
    }

    /**
     * Выдать роли выбранные права и отобрать снятые.
     */
    #[Override]
    public function afterSave(Model $record, mixed $value): void
    {
        RolePermissions::sync($record, is_array($value) ? $value : []);
    }

    /**
     * @return list<string>
     */
    #[Override]
    protected function formatDefault(mixed $value): array
    {
        return is_array($value) ? RolePermissions::normalize($value) : [];
    }

    #[Override]
    protected function typeRules(): array
    {
        return ['array', 'max:'.count(RolePermissions::names())];
    }

    /**
     * Прав в запросе не больше, чем их существует (см. `Field::listLimit()`).
     */
    #[Override]
    public function listLimit(): int
    {
        return count(RolePermissions::names());
    }

    #[Override]
    protected function nestedRules(): array
    {
        return ['*' => ['string', Rule::in(RolePermissions::names())]];
    }

    #[Override]
    public function isMultiple(): bool
    {
        return true;
    }

    /**
     * Права роли для страницы просмотра: «Статьи: просмотр, изменение».
     */
    #[Override]
    public function display(Model $record): ?string
    {
        $granted = RolePermissions::current($record);
        $lines = [];

        foreach ($this->sections() as $section) {
            $groups = array_filter($section['groups'], fn (string $group): bool => in_array('fastleo.'.$section['slug'].'.'.$group, $granted, true));

            if ($groups !== []) {
                $lines[] = $section['label'].': '.implode(', ', array_map(fn (string $group): string => mb_strtolower(__('fastleo::roles.group_'.$group, [], Fastleo::LOCALE)), $groups));
            }
        }

        return $lines === [] ? null : implode('; ', $lines);
    }
}
