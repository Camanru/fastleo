<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\TwoSections;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи со связью на категорию, у которой два раздела.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'articles';

    /**
     * Название и категория.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            BelongsTo::make('category_id', 'Категория'),
        ];
    }
}
