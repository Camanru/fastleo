<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\User;

/**
 * Policy сайта для модели пользователей: админ меняет только себя.
 */
final class SelfOnlyUserPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, User $other): bool
    {
        return true;
    }

    public function update(User $user, User $other): bool
    {
        return $user->is($other);
    }
}
