<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\Feedback;
use Workbench\App\Models\User;

/**
 * Обращения: список открыт, а смотреть запись нельзя.
 */
final class NoViewFeedbackPolicy
{
    /**
     * Список открыт.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Запись открыть нельзя.
     */
    public function view(User $user, Feedback $feedback): bool
    {
        return false;
    }
}
