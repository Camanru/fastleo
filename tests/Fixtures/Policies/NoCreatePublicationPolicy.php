<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\User;

/**
 * Policy публикаций: смотреть можно, создавать нельзя.
 */
final class NoCreatePublicationPolicy
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
