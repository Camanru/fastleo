<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\Article;
use Workbench\App\Models\User;

/**
 * Статьи: всё можно, кроме управления корзиной — вернуть из неё и удалить навсегда нельзя.
 */
final class NoTrashControlArticlePolicy
{
    /**
     * Список открыт.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Запись открыта.
     */
    public function view(User $user, Article $article): bool
    {
        return true;
    }

    /**
     * Менять можно.
     */
    public function update(User $user, Article $article): bool
    {
        return true;
    }

    /**
     * Удалять можно (в корзину).
     */
    public function delete(User $user, Article $article): bool
    {
        return true;
    }

    /**
     * Вернуть из корзины нельзя.
     */
    public function restore(User $user, Article $article): bool
    {
        return false;
    }

    /**
     * Удалить навсегда нельзя.
     */
    public function forceDelete(User $user, Article $article): bool
    {
        return false;
    }
}
