<?php

declare(strict_types=1);

namespace Workbench\App\Observers;

use Workbench\App\Enums\TaskStatus;
use Workbench\App\Models\Task;

/**
 * Логика сайта при записи задачи (так fastleo предлагает делать побочные эффекты — observer модели):
 * статус «Выполнена» ставит время выполнения, другой статус его снимает.
 */
final class TaskObserver
{
    /**
     * Перед записью: отметить или снять время выполнения по статусу.
     */
    public function saving(Task $task): void
    {
        if ($task->status === TaskStatus::Done) {
            $task->completed_at ??= now();
        } else {
            $task->completed_at = null;
        }
    }
}
