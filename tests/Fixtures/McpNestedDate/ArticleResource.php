<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\McpNestedDate;

use Fastleo\Fields\DateTime;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи со ссылками, у которых в колонке `url` хранится дата и время: значение, которое не разбирается как дата.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    /**
     * Заголовок и ссылки с полем даты.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            HasMany::make('links', 'Ссылки')->fields([Text::make('title', 'Название'), DateTime::make('url', 'Дата')]),
        ];
    }
}
