<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Product;
use Workbench\App\Models\ProductBundleItem;

/**
 * Фабрика строк состава комплекта.
 *
 * @extends Factory<ProductBundleItem>
 */
final class ProductBundleItemFactory extends Factory
{
    /** @var class-string<ProductBundleItem> */
    protected $model = ProductBundleItem::class;

    /**
     * Комплект, товар в нём и количество.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'item_id' => Product::factory(),
            'qty' => fake()->numberBetween(1, 5),
            'sort' => 0,
        ];
    }
}
