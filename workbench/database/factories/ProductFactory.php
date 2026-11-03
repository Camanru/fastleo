<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Product;

/**
 * Фабрика товаров демо-сайта.
 *
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    /** @var class-string<Product> */
    protected $model = Product::class;

    /**
     * Товар с ценой и остатком; примечание заполнено не всегда.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->words(3, true),
            'sku' => strtoupper(fake()->unique()->bothify('??-####')),
            'price' => fake()->randomFloat(2, 100, 50000),
            'stock' => fake()->numberBetween(0, 40),
            'status' => fake()->randomElement(['active', 'active', 'draft', 'archived']),
            'is_visible' => fake()->boolean(80),
            'note' => fake()->boolean(20) ? fake()->sentence(4) : null,
        ];
    }
}
