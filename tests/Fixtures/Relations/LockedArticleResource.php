<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Relations;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи, у которых категорию видно, но менять нельзя.
 */
final class LockedArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'locked-articles';

    /**
     * Заголовок и категория только для чтения.
     */
    public function fields(): array
    {
        return [
            Text::make('title'),
            BelongsTo::make('category_id', 'Категория')->readonly(),
        ];
    }
}
