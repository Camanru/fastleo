<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\User;

/**
 * Пользователь с глобальным скоупом (как мягкое удаление или арендатор): пользователя «Призрак» запросы модели не видят,
 * а уникальный индекс почты в базе его видит.
 */
#[Table(name: 'users')]
class ScopedUser extends User
{
    /**
     * Скрыть «Призрака» из всех запросов модели.
     */
    #[\Override]
    protected static function booted(): void
    {
        static::addGlobalScope('hide-ghost', fn (Builder $query): Builder => $query->where('name', '!=', 'Призрак'));
    }
}
