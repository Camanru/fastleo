<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\NestedMustAllow;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Product;

/**
 * Товары с составом комплекта: в комплект можно добавить только товар, который разрешено изменять; товар «Закрытый» изменять нельзя.
 */
final class ProductResource extends Resource
{
    protected static string $model = Product::class;

    protected static ?string $slug = 'products';

    /**
     * Название и состав комплекта.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required(),
            HasMany::make('bundleItems', 'Состав')->fields([BelongsTo::make('item_id', 'Товар')->mustAllow('update')]),
        ];
    }

    /**
     * Товар «Закрытый» изменять нельзя.
     */
    public function can(string $ability, ?Model $record): bool
    {
        return $ability !== 'update' || $record?->getAttribute('title') !== 'Закрытый';
    }
}
