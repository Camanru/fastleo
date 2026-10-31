<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RelatedConstrained;

use Fastleo\Fields\Text;
use Fastleo\Related;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Рубрики с таблицей, в которой связь сама отбирает записи: `Category::publishedArticles()`.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'categories';

    /**
     * Название рубрики.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Название')->required()];
    }

    /**
     * Только опубликованные статьи рубрики и готовые (связь с `orWhere`).
     */
    public function relations(): array
    {
        return [
            Related::make('publishedArticles', ArticleResource::class)->label('Опубликованные'),
            Related::make('readyArticles', ArticleResource::class)->label('Готовые'),
        ];
    }
}
