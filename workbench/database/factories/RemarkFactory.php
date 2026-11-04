<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Article;
use Workbench\App\Models\Remark;

/**
 * Фабрика заметок редакции: по умолчанию заметка о статье.
 *
 * @extends Factory<Remark>
 */
final class RemarkFactory extends Factory
{
    /** @var class-string<Remark> */
    protected $model = Remark::class;

    /**
     * Заметка о случайной статье.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'body' => fake()->sentence(10),
            'subject_type' => Article::class,
            'subject_id' => Article::factory(),
        ];
    }
}
