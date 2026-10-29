<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\User;

/**
 * Policy категорий: раздел недоступен.
 */
final class HiddenCategoryPolicy
{
    /**
     * Список скрыт.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }
}
