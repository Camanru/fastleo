<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Feedback;

/**
 * Фабрика обращений демо-сайта.
 *
 * @extends Factory<Feedback>
 */
final class FeedbackFactory extends Factory
{
    /** @var class-string<Feedback> */
    protected $model = Feedback::class;

    /**
     * Обращение со случайным текстом.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'message' => fake()->paragraph(),
        ];
    }
}
