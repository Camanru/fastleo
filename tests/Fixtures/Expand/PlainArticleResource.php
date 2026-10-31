<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Expand;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи без связанных таблиц.
 */
final class PlainArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'plain-articles';

    /**
     * Название и рубрика.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            BelongsTo::make('category_id', 'Рубрика'),
        ];
    }
}
