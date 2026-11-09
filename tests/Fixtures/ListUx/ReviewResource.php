<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\ListUx;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\ProductReview;

/**
 * Отзывы: товар, к которому привязывают отзыв, должно быть можно изменять, а у товаров в этой папке раздела нет.
 */
final class ReviewResource extends Resource
{
    protected static string $model = ProductReview::class;

    protected static ?string $slug = 'product-reviews';

    /**
     * Автор и товар.
     */
    public function fields(): array
    {
        return [
            Text::make('author', 'Автор')->required(),
            BelongsTo::make('product_id', 'Товар')->required()->mustAllow(),
        ];
    }
}
