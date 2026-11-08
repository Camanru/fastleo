<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Fastleo\Notifications\NotificationBot;
use Workbench\App\Models\User;

/**
 * Policy ботов уведомлений в виде, который пишут по документации: менять можно существующего бота (метод принимает запись),
 * заводить нового нельзя.
 */
final class UpdateOnlyBotPolicy
{
    /**
     * Список виден.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Завести нового бота нельзя.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Изменить сохранённого бота можно.
     */
    public function update(User $user, NotificationBot $bot): bool
    {
        return true;
    }
}
