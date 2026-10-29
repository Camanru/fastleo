<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\Category;
use Workbench\App\Models\User;

/**
 * Policy категорий: список виден, запись «Секрет» смотреть нельзя, менять можно только «Открыто».
 */
final class SecretCategoryPolicy
{
    /**
     * Список виден.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Все записи, кроме «Секрет».
     */
    public function view(User $user, Category $category): bool
    {
        return $category->title !== 'Секрет';
    }

    /**
     * Менять можно только «Открыто».
     */
    public function update(User $user, Category $category): bool
    {
        return $category->title === 'Открыто';
    }
}
