<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Fastleo;
use Fastleo\Page;
use Fastleo\Reports\Choice;
use Fastleo\Reports\Filters;
use Fastleo\Reports\Period;
use Fastleo\Widgets\Chart;
use Fastleo\Widgets\Rows;
use Fastleo\Widgets\Stat;
use Fastleo\Widgets\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;
use Workbench\App\Enums\Priority;
use Workbench\App\Enums\TaskStatus;
use Workbench\App\Models\Supplier;
use Workbench\App\Models\Task;

/**
 * Витрина «Отчёт по задачам» (создан `fastleo:make-page TaskReport --report`): фильтры периода и списков,
 * под ними плитки, график из двух линий, столбчатый график, таблица с итогом и последние задачи раздела.
 * Своего шаблона нет — страницу рисует админка.
 */
final class TaskReportPage extends Page
{
    protected static ?string $label = 'Отчёт по задачам';

    protected static string $icon = 'chart-column';

    protected static ?string $group = 'Витрина: отчёты';

    protected static int $position = 10;

    /**
     * Период (по умолчанию — месяц), поставщик и приоритет.
     */
    #[Override]
    public function filters(): array
    {
        return [
            Period::make('period', 'Создана')->default('month'),
            Choice::make('supplier', 'Поставщик')->options(fn (): array => Supplier::query()->orderBy('name')->get()->mapWithKeys(fn (Supplier $supplier): array => [$supplier->id => $supplier->name])->all()),
            Choice::make('priority', 'Приоритет')->options(collect(Priority::cases())->mapWithKeys(fn (Priority $priority): array => [$priority->value => $priority->label()])->all()),
        ];
    }

    /**
     * Виджеты по задачам, созданным за выбранный период, с учётом поставщика и приоритета.
     */
    #[Override]
    public function widgets(Filters $filters): array
    {
        $period = $filters->period('period');
        $tasks = fn (): Builder => $period->apply(Task::query(), 'tasks.created_at')
            ->when($filters->get('supplier'), fn (Builder $query, mixed $supplier) => $query->where('supplier_id', $supplier))
            ->when($filters->get('priority'), fn (Builder $query, mixed $priority) => $query->where('priority', $priority));

        return [
            Stat::make('Создано')->value(fn (): int => $tasks()->count())->icon('list-todo'),
            Stat::make('Выполнено')->value(fn (): int => $tasks()->where('status', TaskStatus::Done)->count())->icon('circle-check'),
            Stat::make('Просрочено')->value(fn (): int => $tasks()->overdue()->count())->icon('alarm-clock')
                ->description('Открыть в списке')->url(route('fastleo.resources.index', ['tasks', 'filter' => ['deadline' => 'overdue']])),
            Stat::make('Часов в оценке')->value(fn (): int => (int) $tasks()->sum('estimate')),
            Chart::make('По дням')->columnSpan(4)->series(function () use ($tasks, $period): array {
                $created = $this->countByDay($tasks());
                $done = $this->countByDay($tasks()->where('status', TaskStatus::Done));

                return [
                    'Создано' => collect($period->days())->mapWithKeys(fn (string $day): array => [$day => $created[$day] ?? 0])->all(),
                    'Выполнено' => collect($period->days())->mapWithKeys(fn (string $day): array => [$day => $done[$day] ?? 0])->all(),
                ];
            }),
            Chart::make('По статусам')->bar()->label('Задач')->columnSpan(2)->data(fn (): array => collect(TaskStatus::cases())
                ->mapWithKeys(fn (TaskStatus $status): array => [$status->label() => $tasks()->where('status', $status)->count()])->all()),
            Rows::make('По поставщикам')->columnSpan(2)
                ->columns(['Поставщик', 'Задач', 'Часов'])
                ->rows(fn (): array => Supplier::query()->orderBy('name')->get()->map(fn (Supplier $supplier): array => [
                    $supplier->name,
                    $tasks()->where('supplier_id', $supplier->id)->count(),
                    (int) $tasks()->where('supplier_id', $supplier->id)->sum('estimate'),
                ])->all())
                ->footer(fn (): array => ['Итого', $tasks()->count(), (int) $tasks()->sum('estimate')]),
            Table::make('Последние задачи')->resource(TaskResource::class)->limit(5)->columnSpan(4),
        ];
    }

    /**
     * Число задач по дням создания (в часовом поясе админки, как и сам период).
     *
     * @param  Builder<Task>  $query
     * @return array<int>
     */
    private function countByDay(Builder $query): array
    {
        return $query->get()->countBy(fn (Task $task): string => (string) $task->created_at?->setTimezone(Fastleo::timezone())->toDateString())->all();
    }
}
