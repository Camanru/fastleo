<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\LinkNone;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи без раздела категорий: ссылке на категорию вести некуда.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    /**
     * Название и категория.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок'),
            BelongsTo::make('category_id', 'Категория'),
        ];
    }
}
