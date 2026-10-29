<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\SitemapOrder;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Article;

/**
 * Статьи, у которых карта сайта упорядочена по дате создания, а не по ключу.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    /**
     * Заголовок.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Заголовок')->required()];
    }

    /**
     * Адрес статьи на сайте.
     */
    public function url(Model $record): string
    {
        return route('articles.show', $record->getKey());
    }

    /**
     * Опубликованные, новые первыми.
     */
    public function sitemap(Builder $query): Builder
    {
        return $query->where('is_published', true)->latest();
    }
}
