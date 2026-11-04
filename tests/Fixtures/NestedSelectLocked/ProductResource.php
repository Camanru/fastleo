<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\NestedSelectLocked;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Product;

/**
 * Товары, у которых состав комплекта закрыт для изменения: весь блок или только выбор товара (`$locked`: `block` или `field`); `foreign` — в блоке поле с именем внешнего ключа связи.
 */
final class ProductResource extends Resource
{
    protected static string $model = Product::class;

    protected static ?string $slug = 'products';

    /** Что закрыто: `block` — весь состав, `field` — только выбор товара. */
    public static string $locked = 'block';

    /**
     * Название и состав комплекта.
     */
    public function fields(): array
    {
        $item = BelongsTo::make('item_id', 'Товар');
        $bundle = HasMany::make('bundleItems', 'Состав');

        if (self::$locked === 'foreign') {
            return [Text::make('title', 'Название')->required(), $bundle->fields([BelongsTo::make('product_id', 'Товар')])];
        }

        return [
            Text::make('title', 'Название')->required(),
            (self::$locked === 'block' ? $bundle->readonly() : $bundle)->fields([self::$locked === 'field' ? $item->readonly() : $item]),
        ];
    }
}
