<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\User;

/**
 * Policy ботов уведомлений: смотреть можно, менять нельзя.
 */
final class ReadOnlyBotPolicy
{
    /**
     * Список виден.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Менять нельзя.
     */
    public function update(User $user): bool
    {
        return false;
    }
}
