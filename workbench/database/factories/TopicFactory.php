<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Topic;

/**
 * Фабрика тем демо-сайта.
 *
 * @extends Factory<Topic>
 */
final class TopicFactory extends Factory
{
    /** @var class-string<Topic> */
    protected $model = Topic::class;

    /**
     * Тема со случайным названием.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['title' => fake()->unique()->words(2, true)];
    }
}
