<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo\Actions;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Fastleo\Fields\Date;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Task;

/**
 * Витрина: действие с мини-формой — перенести срок выбранных задач, дописать причину в описание и снять срочность.
 */
final class PostponeTasks extends Action
{
    protected ?string $label = 'Перенести срок';

    protected string $icon = 'calendar-clock';

    /**
     * Новый срок (обязательно), причина и галочка «снять срочность».
     */
    public function fields(): array
    {
        return [
            Date::make('due_on', 'Новый срок')->required()->rules(['after_or_equal:today']),
            Text::make('reason', 'Причина')->maxLength(200),
            Toggle::make('not_urgent', 'Снять срочность'),
        ];
    }

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        $reason = is_string($data['reason'] ?? null) ? $data['reason'] : null;

        foreach ($records as $task) {
            if ($task instanceof Task) {
                $task->update([
                    'due_on' => $data['due_on'],
                    'description' => $reason === null ? $task->description : trim($task->description."\n\nПеренесено: ".$reason),
                    'is_urgent' => $data['not_urgent'] === true ? false : $task->is_urgent,
                ]);
            }
        }

        return ActionResult::success("Срок перенесён: {$records->count()}");
    }
}
