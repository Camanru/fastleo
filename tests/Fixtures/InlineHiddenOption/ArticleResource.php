<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\InlineHiddenOption;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Article;

/**
 * Категория правится в ячейке, но выбрать можно только категории с кодом: сохранённая категория без кода — вне вариантов.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'coded-category-articles';

    /**
     * Заголовок и категория с ограниченными вариантами.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            BelongsTo::make('category_id', 'Категория')->query(fn (Builder $query) => $query->whereNotNull('code'))->inlineEditable(),
        ];
    }
}
