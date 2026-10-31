<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\Category;
use Workbench\App\Models\User;

/**
 * Рубрики: список и правка открыты, а смотреть запись нельзя.
 */
final class UpdateNoViewCategoryPolicy
{
    /**
     * Список виден.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Запись смотреть нельзя.
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
