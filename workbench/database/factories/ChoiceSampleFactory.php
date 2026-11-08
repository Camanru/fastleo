<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Enums\Priority;
use Workbench\App\Models\ChoiceSample;

/**
 * Фабрика записей витрины «Выбор и даты».
 *
 * @extends Factory<ChoiceSample>
 */
final class ChoiceSampleFactory extends Factory
{
    /** @var class-string<ChoiceSample> */
    protected $model = ChoiceSample::class;

    /**
     * Запись с заполненными полями витрины.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->words(2, true),
            'size' => fake()->randomElement(['s', 'm', 'l']),
            'colors' => fake()->randomElements(['red', 'green', 'blue', 'black'], 2),
            'stage' => fake()->randomElement(['new', 'packing', 'shipped']),
            'shelf' => fake()->randomElement([0, 1, 2, 3]),
            'brand' => 'apple',
            'model' => 'iphone',
            'priority' => fake()->randomElement(Priority::cases()),
            'delivery' => 'courier',
            'address' => fake()->streetAddress(),
            'is_gift' => false,
            'is_active' => fake()->boolean(80),
            'labels' => ['хит', 'новинка'],
            'available_on' => fake()->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d'),
            'starts_at' => fake()->dateTimeBetween('-1 week', '+1 week'),
            'opens_at' => '09:00',
        ];
    }
}
