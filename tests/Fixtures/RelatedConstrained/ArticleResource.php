<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RelatedConstrained;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Article;

/**
 * Статьи — дочерний раздел связанной таблицы с условием в методе связи; избранные скрыты `query()`.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'articles';

    /**
     * Заголовок статьи.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Заголовок')->required()->searchable()];
    }

    /**
     * Избранные статьи в разделе не видны.
     */
    public function query(Builder $query): Builder
    {
        return $query->where('is_featured', false);
    }
}
