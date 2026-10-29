<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Relations;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Статьи, у которых категорию можно выбрать только среди категорий с кодом, а темы — только «Открытые…».
 */
final class ScopedArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'scoped-articles';

    /**
     * Заголовок, категория с ограничением и статус по умолчанию.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            BelongsTo::make('category_id', 'Категория')->query(fn (Builder $query) => $query->whereNotNull('code')),
            BelongsToMany::make('topics', 'Темы')->query(fn (Builder $query) => $query->where('title', 'like', 'Открытая%'))->showInList(),
            Text::make('status')->default(ArticleStatus::Draft->value)->hideInList(),
        ];
    }
}
