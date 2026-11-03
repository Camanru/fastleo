<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RelatedNeighbour;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Related\StampArticles;
use Workbench\App\Models\Article;

/**
 * Статьи — дочерний раздел рубрик с действием, у которого есть мини-форма с проверкой на сервере.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'articles';

    /**
     * Заголовок.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Заголовок')->required()];
    }

    /**
     * Действие с мини-формой.
     */
    public function actions(): array
    {
        return [new StampArticles];
    }
}
