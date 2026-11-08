<?php

declare(strict_types=1);

namespace Workbench\App\Policies;

use Workbench\App\Enums\TaskStatus;
use Workbench\App\Models\Task;
use Workbench\App\Models\User;

/**
 * Права на задачи витрины: выполненную задачу нельзя удалить, отменить можно только незакрытую (своё право `cancel`
 * для действия CancelTask); остальное разрешено.
 */
final class TaskPolicy
{
    /**
     * Список задач.
     */
    public function viewAny(): bool
    {
        return true;
    }

    /**
     * Просмотр задачи.
     */
    public function view(): bool
    {
        return true;
    }

    /**
     * Создание задачи.
     */
    public function create(): bool
    {
        return true;
    }

    /**
     * Изменение задачи.
     */
    public function update(): bool
    {
        return true;
    }

    /**
     * Удаление: выполненная задача остаётся в истории.
     */
    public function delete(User $user, Task $task): bool
    {
        return $task->status !== TaskStatus::Done;
    }

    /**
     * Отмена задачи (право действия CancelTask): только незакрытые.
     */
    public function cancel(User $user, Task $task): bool
    {
        return ! $task->status->isClosed();
    }
}
