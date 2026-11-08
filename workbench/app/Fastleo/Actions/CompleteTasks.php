<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo\Actions;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Override;
use Workbench\App\Enums\TaskStatus;
use Workbench\App\Models\Task;

/**
 * Витрина: действие с вопросом перед запуском — отметить задачи выполненными (у одной записи и у выбранных).
 * Кнопка не показывается у уже закрытой задачи (`visibleFor()`); время выполнения ставит TaskObserver.
 */
final class CompleteTasks extends Action
{
    protected ?string $label = 'Выполнено';

    protected string $icon = 'check-check';

    protected ?string $confirm = 'Отметить задачи выполненными?';

    /**
     * Кнопка у записи — только у незакрытой задачи.
     */
    #[Override]
    public function visibleFor(Model $record): bool
    {
        return ! $record instanceof Task || ! $record->status->isClosed();
    }

    /**
     * Перевести незакрытые задачи в «Выполнена»; закрытые пропускаются.
     *
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        $done = 0;

        foreach ($records as $task) {
            if ($task instanceof Task && ! $task->status->isClosed()) {
                $task->update(['status' => TaskStatus::Done]);
                $done++;
            }
        }

        $skipped = $records->count() - $done;

        return ActionResult::success("Выполнено: {$done}".($skipped > 0 ? ". Уже закрыты: {$skipped}" : ''));
    }
}
