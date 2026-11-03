<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\LinkInline;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи, у которых категория правится в ячейке списка: ссылки на неё нет.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    /**
     * Название и категория с правкой на месте.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок'),
            BelongsTo::make('category_id', 'Категория')->inlineEditable(),
        ];
    }
}
