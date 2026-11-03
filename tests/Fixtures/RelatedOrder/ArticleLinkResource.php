<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RelatedOrder;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\ArticleLink;

/**
 * Полезные ссылки статьи в ручном порядке (колонка `sort`).
 */
final class ArticleLinkResource extends Resource
{
    protected static string $model = ArticleLink::class;

    protected static ?string $slug = 'article-links';

    protected static ?string $sortColumn = 'sort';

    /**
     * Заголовок ссылки и статья.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required()->searchable()->sortable(),
            BelongsTo::make('article_id', 'Статья')->required(),
        ];
    }
}
