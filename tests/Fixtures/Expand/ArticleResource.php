<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Expand;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Related;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи со своими ссылками: раскрываются в строке рубрики.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'articles';

    /**
     * Название и рубрика.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required()->searchable(),
            BelongsTo::make('category_id', 'Рубрика')->required(),
        ];
    }

    /**
     * Ссылки статьи (третий уровень).
     */
    public function relations(): array
    {
        return [Related::make('links', LinkResource::class)->label('Ссылки статьи')];
    }
}
