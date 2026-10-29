<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\Topic;
use Workbench\App\Models\User;

/**
 * Темы: без метода reorder — порядок меняет тот, кто может менять запись (только «Своя…»).
 */
final class TopicUpdatePolicy
{
    /**
     * Список виден.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Менять — только свои темы.
     */
    public function update(User $user, Topic $topic): bool
    {
        return str_starts_with($topic->title, 'Своя');
    }
}
