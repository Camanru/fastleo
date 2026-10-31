<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\Category;
use Workbench\App\Models\User;

/**
 * Рубрики: список виден, открыть запись нельзя.
 */
final class NoViewCategoryPolicy
{
    /**
     * Список виден.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Запись не открывается.
     */
    public function view(User $user, Category $category): bool
    {
        return false;
    }

    /**
     * Менять можно.
     */
    public function update(User $user, Category $category): bool
    {
        return true;
    }
}
