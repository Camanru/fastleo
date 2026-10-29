<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\BrokenDependency;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Article;

/**
 * Статьи, у которых зависимый список даёт ошибку базы — как строка вместо числа в колонке PostgreSQL.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    /**
     * Название, категория и подраздел с запросом по несуществующей колонке.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            BelongsTo::make('category_id', 'Категория'),
            BelongsTo::make('subcategory_id', 'Подраздел')
                ->dependsOn('category_id', fn (Builder $query, mixed $category) => $query->whereRaw('no_such_column = ?', [$category])),
        ];
    }
}
