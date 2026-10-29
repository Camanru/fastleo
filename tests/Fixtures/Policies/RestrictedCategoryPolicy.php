<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\Category;
use Workbench\App\Models\User;

/**
 * Policy категорий: смотреть можно, создавать и удалять нельзя, менять — кроме «Закрыто».
 */
final class RestrictedCategoryPolicy
{
    /**
     * Список виден.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Запись видна.
     */
    public function view(User $user, Category $category): bool
    {
        return true;
    }

    /**
     * Создавать нельзя.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Менять можно всё, кроме категории «Закрыто».
     */
    public function update(User $user, Category $category): bool
    {
        return $category->title !== 'Закрыто';
    }
}
