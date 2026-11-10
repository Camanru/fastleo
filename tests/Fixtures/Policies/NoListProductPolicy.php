<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\User;

/**
 * Товары: список закрыт (раздел для пользователя недоступен).
 */
final class NoListProductPolicy
{
    /**
     * Список закрыт.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }
}
