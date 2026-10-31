<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\User;

/**
 * Статьи: раздел закрыт.
 */
final class HiddenArticlePolicy
{
    /**
     * Список закрыт.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }
}
