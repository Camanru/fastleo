<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Enums\Priority;
use Workbench\App\Enums\TaskStatus;
use Workbench\App\Models\Task;

/**
 * Фабрика записей витрины «Задачи».
 *
 * @extends Factory<Task>
 */
final class TaskFactory extends Factory
{
    /** @var class-string<Task> */
    protected $model = Task::class;

    /**
     * Запись с заполненными полями витрины.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->boolean(60) ? fake()->sentence(10) : null,
            'status' => fake()->randomElement([TaskStatus::New, TaskStatus::New, TaskStatus::InProgress]),
            'priority' => fake()->randomElement(Priority::cases()),
            'due_on' => fake()->dateTimeBetween('-10 days', '+20 days')->format('Y-m-d'),
            'estimate' => fake()->numberBetween(1, 40),
            'is_urgent' => fake()->boolean(20),
        ];
    }

    /**
     * Выполненная задача (`completed_at` поставит TaskObserver).
     */
    public function done(): static
    {
        return $this->state(['status' => TaskStatus::Done]);
    }

    /**
     * Просроченная незакрытая задача.
     */
    public function overdue(): static
    {
        return $this->state(['status' => TaskStatus::InProgress, 'due_on' => today()->subDays(3)->toDateString()]);
    }
}
