<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Aside;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи рубрики для боковой колонки.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'articles';

    /**
     * Заголовок и рубрика.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            BelongsTo::make('category_id', 'Рубрика')->required(),
        ];
    }
}
