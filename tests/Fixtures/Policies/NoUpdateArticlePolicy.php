<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\Article;
use Workbench\App\Models\User;

/**
 * Статьи: смотреть можно, менять нельзя.
 */
final class NoUpdateArticlePolicy
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
    public function view(User $user, Article $article): bool
    {
        return true;
    }

    /**
     * Менять нельзя.
     */
    public function update(User $user, Article $article): bool
    {
        return false;
    }
}
