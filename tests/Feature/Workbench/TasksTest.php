<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Support\Menu;
use Illuminate\Support\Facades\Date;
use Workbench\App\Enums\TaskStatus;
use Workbench\App\Models\Supplier;
use Workbench\App\Models\Task;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

describe('observer', function (): void {
    it('stamps the completion time when a task becomes done and clears it when reopened', function (): void {
        Date::setTestNow('2026-11-08 12:00:00');
        $task = Task::factory()->create();

        $task->update(['status' => TaskStatus::Done]);
        expect($task->refresh()->completed_at?->toDateTimeString())->toBe('2026-11-08 12:00:00');

        $task->update(['status' => TaskStatus::InProgress]);
        expect($task->refresh()->completed_at)->toBeNull();
    });
});

describe('actions', function (): void {
    it('completes the selected open tasks and skips closed ones', function (): void {
        $open = Task::factory()->count(2)->create();
        $cancelled = Task::factory()->create(['status' => TaskStatus::Cancelled]);

        $this->post(route('fastleo.resources.action', ['tasks', 'complete-tasks']), ['ids' => [...$open->modelKeys(), $cancelled->id]])
            ->assertSessionHas('fastleo.success', 'Выполнено: 2. Уже закрыты: 1');

        expect($open->map(fn (Task $task): TaskStatus => $task->refresh()->status)->all())->toBe([TaskStatus::Done, TaskStatus::Done])
            ->and($cancelled->refresh()->status)->toBe(TaskStatus::Cancelled);
    });

    it('postpones tasks with the date and reason from the mini form', function (): void {
        $task = Task::factory()->create(['description' => 'Позвонить', 'is_urgent' => true]);
        $due = today()->addDays(5)->toDateString();

        $this->post(route('fastleo.resources.action', ['tasks', 'postpone-tasks']), ['ids' => [$task->id], 'data' => ['due_on' => $due, 'reason' => 'Ждём ответа', 'not_urgent' => '1']])
            ->assertSessionHas('fastleo.success', 'Срок перенесён: 1');

        expect($task->refresh())
            ->due_on->toDateString()->toBe($due)
            ->description->toBe("Позвонить\n\nПеренесено: Ждём ответа")
            ->is_urgent->toBeFalse();
    });

    it('requires a new due date that is not in the past', function (): void {
        $task = Task::factory()->create(['due_on' => '2030-01-01']);

        $this->post(route('fastleo.resources.action', ['tasks', 'postpone-tasks']), ['ids' => [$task->id], 'data' => ['due_on' => today()->subDay()->toDateString()]])
            ->assertSessionHasErrors();

        expect($task->refresh()->due_on?->toDateString())->toBe('2030-01-01');
    });

    it('cancels an open task but not a closed one', function (): void {
        $open = Task::factory()->create();
        $done = Task::factory()->done()->create();

        $this->post(route('fastleo.resources.action', ['tasks', 'cancel-task']), ['ids' => [$open->id]])->assertSessionHas('fastleo.success');
        $this->post(route('fastleo.resources.action', ['tasks', 'cancel-task']), ['ids' => [$done->id]]);

        expect($open->refresh()->status)->toBe(TaskStatus::Cancelled)
            ->and($done->refresh()->status)->toBe(TaskStatus::Done);
    });

    it('downloads the selected tasks as a text file', function (): void {
        $task = Task::factory()->create(['title' => 'Заказать коробки', 'status' => TaskStatus::New, 'due_on' => '2026-12-01']);

        $response = $this->post(route('fastleo.resources.action', ['tasks', 'download-tasks']), ['ids' => [$task->id]])->assertOk();

        expect($response->streamedContent())->toContain('- Заказать коробки — Новая, срок 01.12.2026');
    });
});

describe('policy', function (): void {
    it('does not delete a done task', function (): void {
        $task = Task::factory()->done()->create();

        $this->delete(route('fastleo.resources.destroy', ['tasks', $task->id]))->assertForbidden();

        $this->assertModelExists($task);
    });

    it('deletes an open task', function (): void {
        $task = Task::factory()->create();

        $this->delete(route('fastleo.resources.destroy', ['tasks', $task->id]))->assertRedirect();

        $this->assertModelMissing($task);
    });
});

it('shows the number of overdue open tasks next to the menu item', function (): void {
    Task::factory()->overdue()->count(2)->create();
    Task::factory()->done()->create(['due_on' => today()->subDays(3)->toDateString()]);
    Task::factory()->create(['due_on' => today()->addDay()->toDateString()]);

    $this->get(route('fastleo.resources.index', 'tasks'))->assertOk();

    $item = collect(Menu::groups())->flatMap(fn (array $group): array => $group['items'])->firstWhere('label', 'Задачи');

    expect($item['badge'] ?? null)->toBe(2);
});

it('opens the list with only open tasks by default', function (): void {
    Task::factory()->create(['title' => 'Открытая задача']);
    Task::factory()->done()->create(['title' => 'Закрытая задача']);

    $this->get(route('fastleo.resources.index', 'tasks'))->assertSee('Открытая задача')->assertDontSee('Закрытая задача');
    $this->get(route('fastleo.resources.index', ['tasks', 'filter' => ['state' => 'closed']]))->assertSee('Закрытая задача')->assertDontSee('Открытая задача');
});

it('opens the task report with filters applied', function (): void {
    $supplier = Supplier::factory()->create(['name' => 'ООО Коробки']);
    Task::factory()->create(['title' => 'Задача из отчёта', 'priority' => 3, 'supplier_id' => $supplier->id]);

    $this->get(route('fastleo.pages.show', ['task-report', 'filter' => ['period' => ['from' => today()->subWeek()->toDateString(), 'to' => today()->toDateString()], 'priority' => '3']]))
        ->assertOk()
        ->assertSee('Отчёт по задачам')
        ->assertSee(['По поставщикам', 'ООО Коробки', 'Итого', 'Задача из отчёта'])
        ->assertDontSee('Не удалось загрузить');
});
