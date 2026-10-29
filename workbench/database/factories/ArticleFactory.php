<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Фабрика статей демо-сайта. По умолчанию — черновик.
 *
 * @extends Factory<Article>
 */
final class ArticleFactory extends Factory
{
    /** @var class-string<Article> */
    protected $model = Article::class;

    /**
     * Черновик со случайным заголовком.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'slug' => fake()->unique()->slug(3),
            'excerpt' => fake()->paragraph(),
            'status' => ArticleStatus::Draft,
            'tags' => ['news'],
            'views' => fake()->numberBetween(0, 5000),
            'price' => fake()->randomFloat(2, 0, 10000),
            'published_on' => fake()->dateTimeBetween('-90 days')->format('Y-m-d'),
            'author_email' => fake()->safeEmail(),
            'color' => fake()->hexColor(),
        ];
    }

    /**
     * Опубликованная статья.
     */
    public function published(): self
    {
        return $this->state(['status' => ArticleStatus::Published, 'is_published' => true]);
    }
}
