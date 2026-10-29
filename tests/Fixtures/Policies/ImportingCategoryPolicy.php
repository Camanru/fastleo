<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\Category;
use Workbench\App\Models\User;

/**
 * Policy категорий со своим правом импорта: загружать файл можно, создавать нельзя,
 * менять — кроме «Закрыто».
 */
final class ImportingCategoryPolicy
{
    /**
     * Список виден.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Загружать файл можно.
     */
    public function import(User $user): bool
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
