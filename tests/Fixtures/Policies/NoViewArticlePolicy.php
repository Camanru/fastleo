<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\Article;
use Workbench\App\Models\User;

/**
 * Статьи: список и создание открыты, а смотреть чужую запись нельзя.
 */
final class NoViewArticlePolicy
{
    /**
     * Список открыт.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Создание открыто.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Запись открыть нельзя.
     */
    public function view(User $user, Article $article): bool
    {
        return false;
    }
}
