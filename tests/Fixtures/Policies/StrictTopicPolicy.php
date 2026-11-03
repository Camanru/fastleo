<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\Topic;
use Workbench\App\Models\User;

/**
 * Policy в том виде, как её создаёт `fastleo:make-policy`: права на запись требуют саму запись.
 */
final class StrictTopicPolicy
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
    public function view(User $user, Topic $topic): bool
    {
        return true;
    }

    /**
     * Менять можно любую запись.
     */
    public function update(User $user, Topic $topic): bool
    {
        return true;
    }

    /**
     * Создавать можно.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Восстанавливать можно любую запись.
     */
    public function restore(User $user, Topic $topic): bool
    {
        return true;
    }
}
