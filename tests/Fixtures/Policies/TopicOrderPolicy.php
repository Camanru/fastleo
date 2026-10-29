<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\Topic;
use Workbench\App\Models\User;

/**
 * Темы: смотреть и менять можно, менять порядок — только у тем «Подвижная…».
 */
final class TopicOrderPolicy
{
    /**
     * Список виден.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Менять можно всё.
     */
    public function update(User $user, Topic $topic): bool
    {
        return true;
    }

    /**
     * Порядок — только у подвижных тем.
     */
    public function reorder(User $user, Topic $topic): bool
    {
        return str_starts_with($topic->title, 'Подвижная');
    }
}
