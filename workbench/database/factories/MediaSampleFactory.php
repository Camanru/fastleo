<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\MediaSample;

/**
 * Фабрика записей витрины «Файлы и медиа».
 *
 * @extends Factory<MediaSample>
 */
final class MediaSampleFactory extends Factory
{
    /** @var class-string<MediaSample> */
    protected $model = MediaSample::class;

    /**
     * Запись с заполненными полями витрины.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->words(2, true),
            'video' => 'https://youtu.be/dQw4w9WgXcQ',
        ];
    }
}
