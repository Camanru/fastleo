<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\SearchUsingOnly;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Article;

/**
 * Единственное искомое поле со своим условием: для строки короче четырёх цифр хук ничего не добавляет.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    public function fields(): array
    {
        return [
            Text::make('title', 'Телефон')->searchUsing(function (Builder $query, string $term, string $column): void {
                if (strlen($term) >= 4) {
                    $query->where($column, 'like', '%'.$term.'%');
                }
            }),
        ];
    }
}
