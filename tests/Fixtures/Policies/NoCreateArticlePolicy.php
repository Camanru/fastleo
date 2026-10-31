<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\User;

/**
 * Статьи: список виден, создавать нельзя.
 */
final class NoCreateArticlePolicy
{
    /**
     * Список виден.
     */
    public function viewAny(User $user): bool
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
}
