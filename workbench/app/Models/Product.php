<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Workbench\Database\Factories\ProductFactory;

/**
 * Товар демо-сайта: цена и остаток правятся в списке; поставщик — отдельная таблица, товар ссылается на неё.
 *
 * @property int $id
 * @property string $title
 * @property string $price
 * @property int $stock
 * @property string $status
 * @property bool $is_visible
 */
#[Fillable(['category_id', 'supplier_id', 'title', 'sku', 'photo', 'price', 'stock', 'status', 'is_visible', 'note'])]
final class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * Фабрика модели.
     */
    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /**
     * Касты: галочка.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_visible' => 'boolean'];
    }

    /**
     * Категория товара.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Поставщик товара.
     *
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Товар-спутник: первая строка состава (одиночный блок в форме).
     *
     * @return HasOne<ProductBundleItem, $this>
     */
    public function companion(): HasOne
    {
        return $this->hasOne(ProductBundleItem::class)->orderBy('sort');
    }

    /**
     * Состав комплекта: товары и их количество (блоки в форме).
     *
     * @return HasMany<ProductBundleItem, $this>
     */
    public function bundleItems(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class)->orderBy('sort');
    }

    /**
     * Отзывы о товаре.
     *
     * @return HasMany<ProductReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }
}
