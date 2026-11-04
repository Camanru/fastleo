<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\NestedSelect;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\Number;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Product;

/**
 * Товары с составом комплекта: в блоке выбрать можно только товары «в продаже».
 */
final class ProductResource extends Resource
{
    protected static string $model = Product::class;

    protected static ?string $slug = 'products';

    /** Один и тот же товар в составе дважды не нужен (правило `distinct`). */
    public static bool $distinct = false;

    /**
     * Название и состав комплекта.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required(),
            HasMany::make('bundleItems', 'Состав')->orderColumn('sort')->fields([
                BelongsTo::make('item_id', 'Товар')->query(fn (Builder $query) => $query->where('status', 'active'))->rules(self::$distinct ? ['distinct'] : []),
                Number::make('qty', 'Количество')->integer()->min(1)->default(1),
            ]),
        ];
    }
}
