<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Number;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;
use Workbench\App\Models\ProductReview;

/**
 * Отзывы о товарах: показываются таблицей на странице товара (`Related`), модерация — галочкой в ячейке.
 */
final class ProductReviewResource extends Resource
{
    protected static string $model = ProductReview::class;

    protected static ?string $label = 'Отзывы';

    protected static ?string $singular = 'Отзыв';

    protected static string $icon = 'message-square';

    protected static ?string $group = 'Каталог';

    protected static int $position = 20;

    /**
     * Товар, автор, оценка, текст и модерация.
     */
    public function fields(): array
    {
        return [
            Text::make('author', 'Автор')->required()->searchable()->sortable(),
            BelongsTo::make('product_id', 'Товар')->required(),
            Number::make('rating', 'Оценка')->integer()->min(1)->max(5)->default(5)->inlineEditable()->sortable(),
            Textarea::make('text', 'Текст')->rows(3)->hideInList(),
            Toggle::make('is_approved', 'Одобрен')->quickToggle(),
        ];
    }
}
