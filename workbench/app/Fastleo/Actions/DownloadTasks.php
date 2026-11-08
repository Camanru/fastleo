<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo\Actions;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Task;

/**
 * Витрина: действие только для выбранных (`$single = false`), которое ничего не меняет (`$ability = 'view'`)
 * и отдаёт файл (`ActionResult::download()`): список задач для печати.
 */
final class DownloadTasks extends Action
{
    protected ?string $label = 'Скачать список';

    protected string $icon = 'download';

    protected string $ability = 'view';

    protected bool $single = false;

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        $lines = $records->map(fn (Model $task): string => $task instanceof Task
            ? '- '.$task->title.' — '.$task->status->label().($task->due_on === null ? '' : ', срок '.$task->due_on->format('d.m.Y'))
            : '')->filter()->implode("\n");

        return ActionResult::download(response()->streamDownload(function () use ($lines): void {
            echo "Задачи\n\n".$lines."\n";
        }, 'tasks.txt', ['Content-Type' => 'text/plain; charset=UTF-8']));
    }
}
