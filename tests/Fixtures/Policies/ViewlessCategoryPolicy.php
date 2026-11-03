<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\User;

/**
 * Policy категорий: список открыт, страницы записей — нет.
 */
final class ViewlessCategoryPolicy
{
    /**
     * Список виден.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Страница записи закрыта.
     */
    public function view(User $user): bool
    {
        return false;
    }
}
