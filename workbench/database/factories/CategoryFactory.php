<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Category;

/**
 * Фабрика категорий демо-сайта.
 *
 * @extends Factory<Category>
 */
final class CategoryFactory extends Factory
{
    /** @var class-string<Category> */
    protected $model = Category::class;

    /**
     * Категория со случайным названием и кодом.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->slug(2),
        ];
    }
}
