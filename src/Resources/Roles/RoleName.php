<?php

declare(strict_types=1);

namespace Fastleo\Resources\Roles;

use Fastleo\Auth\RoleMatrix;
use Fastleo\Fastleo;
use Fastleo\Fields\Text;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Override;

/**
 * Название роли: уникально среди ролей guard `fastleo`; сама запись всегда создаётся под этим guard.
 */
final class RoleName extends Text
{
    /**
     * Правила текста и уникальность названия среди ролей guard `fastleo`.
     *
     * @return array<string, list<mixed>>
     */
    #[Override]
    public function getRules(?Model $record): array
    {
        $rules = parent::getRules($record);
        $table = RoleMatrix::roleModel()?->getTable();

        if ($table !== null) {
            $rules[$this->name][] = Rule::unique($table, 'name')->where('guard_name', Fastleo::GUARD)->ignore($record?->getKey());
        }

        return $rules;
    }

    /**
     * Роль принадлежит guard `fastleo`, что бы ни пришло в запросе.
     */
    #[Override]
    public function beforeSave(Model $record): void
    {
        $record->setAttribute('guard_name', Fastleo::GUARD);
    }
}
