<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Product;
use Workbench\App\Models\ProductReview;

/**
 * Фабрика отзывов о товарах.
 *
 * @extends Factory<ProductReview>
 */
final class ProductReviewFactory extends Factory
{
    /** @var class-string<ProductReview> */
    protected $model = ProductReview::class;

    /**
     * Отзыв с оценкой.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'author' => fake()->firstName(),
            'rating' => fake()->numberBetween(1, 5),
            'text' => fake()->sentence(8),
            'is_approved' => fake()->boolean(60),
        ];
    }
}
