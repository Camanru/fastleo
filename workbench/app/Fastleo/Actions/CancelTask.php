<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo\Actions;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Enums\TaskStatus;
use Workbench\App\Models\Task;

/**
 * Витрина: опасное действие (`$danger`, красная кнопка и вопрос) только у одной записи (`$bulk = false`),
 * со своим правом Policy (`$ability = 'cancel'`: закрытую задачу отменить нельзя) и без запуска через MCP (`$mcp = false`).
 */
final class CancelTask extends Action
{
    protected ?string $label = 'Отменить задачу';

    protected string $icon = 'ban';

    protected ?string $confirm = 'Отменить задачу? Вернуть её можно, только сменив статус вручную.';

    protected bool $danger = true;

    protected string $ability = 'cancel';

    protected bool $bulk = false;

    protected bool $mcp = false;

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        $records->each(fn (Model $task): bool => $task instanceof Task && $task->update(['status' => TaskStatus::Cancelled]));

        return ActionResult::success('Задача отменена.');
    }
}
