<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\TextSample;

/**
 * Фабрика записей витрины «Текст и числа».
 *
 * @extends Factory<TextSample>
 */
final class TextSampleFactory extends Factory
{
    /** @var class-string<TextSample> */
    protected $model = TextSample::class;

    /**
     * Запись с заполненными полями витрины.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->words(3, true),
            'subtitle' => 'Новинка',
            'sku' => strtoupper(fake()->bothify('TX-####')),
            'internal_code' => 'INT-'.fake()->numberBetween(100, 999),
            'note' => fake()->boolean(30) ? fake()->sentence(3) : null,
            'description' => fake()->paragraph(),
            'quantity' => fake()->numberBetween(0, 500),
            'price' => fake()->randomFloat(2, 10, 9999),
            'rating' => fake()->numberBetween(0, 10),
            'email' => fake()->safeEmail(),
            'phone' => '+7 900 '.fake()->numerify('###-##-##'),
            'website' => 'https://example.com/'.fake()->slug(2),
            'color' => fake()->hexColor(),
            'snippet' => '<span class="promo">-10%</span>',
            'source' => 'admin',
            'promo_code' => strtoupper(fake()->bothify('SALE-##??')),
        ];
    }
}
