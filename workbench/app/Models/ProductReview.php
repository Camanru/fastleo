<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workbench\Database\Factories\ProductReviewFactory;

/**
 * Отзыв о товаре: показывается таблицей на странице товара.
 *
 * @property int $id
 * @property string $author
 * @property int $rating
 * @property bool $is_approved
 */
#[Fillable(['product_id', 'author', 'rating', 'text', 'is_approved'])]
final class ProductReview extends Model
{
    /** @use HasFactory<ProductReviewFactory> */
    use HasFactory;

    /**
     * Фабрика модели.
     */
    protected static function newFactory(): ProductReviewFactory
    {
        return ProductReviewFactory::new();
    }

    /**
     * Касты: галочка.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_approved' => 'boolean'];
    }

    /**
     * Товар отзыва.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
