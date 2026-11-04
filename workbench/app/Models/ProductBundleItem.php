<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workbench\Database\Factories\ProductBundleItemFactory;

/**
 * Строка состава комплекта: какой товар и сколько (блок `HasMany` с выбором записи `BelongsTo`).
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $item_id
 * @property int $qty
 * @property int $sort
 */
#[Fillable(['item_id', 'qty'])]
final class ProductBundleItem extends Model
{
    /** @use HasFactory<ProductBundleItemFactory> */
    use HasFactory;

    /**
     * Фабрика модели.
     */
    protected static function newFactory(): ProductBundleItemFactory
    {
        return ProductBundleItemFactory::new();
    }

    /**
     * Комплект, в который входит строка.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Товар в составе.
     *
     * @return BelongsTo<Product, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'item_id');
    }
}
