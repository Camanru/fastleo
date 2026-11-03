<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Supplier;

/**
 * Фабрика поставщиков демо-сайта.
 *
 * @extends Factory<Supplier>
 */
final class SupplierFactory extends Factory
{
    /** @var class-string<Supplier> */
    protected $model = Supplier::class;

    /**
     * Поставщик; телефон и примечание есть не у всех.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'phone' => fake()->boolean(70) ? '+7 900 '.fake()->numerify('###-##-##') : null,
            'note' => fake()->boolean(30) ? fake()->sentence(4) : null,
        ];
    }
}
