<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Actions;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Article;

/**
 * Новости (только type = news) со своими действиями — для тестов действий.
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
     * Только новости.
     */
    public function query(Builder $query): Builder
    {
        return $query->where('type', 'news');
    }

    /**
     * Свои действия.
     */
    public function actions(): array
    {
        return [new MarkFeatured, new RenameArticles, new ExportTitles, new ArchiveArticle, new BrokenAction];
    }
}
