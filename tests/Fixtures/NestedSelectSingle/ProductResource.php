<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\NestedSelectSingle;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\HasOne;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Product;

/**
 * Товары с одним выбранным товаром-спутником (блок `HasOne`): выбрать можно только товары «в продаже».
 */
final class ProductResource extends Resource
{
    protected static string $model = Product::class;

    protected static ?string $slug = 'products';

    /**
     * Название и спутник.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required(),
            HasOne::make('companion', 'Спутник')->fields([
                BelongsTo::make('item_id', 'Товар')->query(fn (Builder $query) => $query->where('status', 'active')),
            ]),
        ];
    }
}
