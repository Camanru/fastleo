<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\User;

/**
 * Ссылки статей: список закрыт.
 */
final class NoViewLinkPolicy
{
    /**
     * Список закрыт.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }
}
