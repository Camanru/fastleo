<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Expand;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Related;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Рубрики: статьи рубрики раскрываются (внутри статьи — её ссылки), подрубрики раскрываются так же — на любую глубину.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'categories';

    /**
     * Название рубрики.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required(),
            BelongsTo::make('parent_id', 'Родитель'),
        ];
    }

    /**
     * Статьи и подрубрики рубрики с раскрывающимися строками.
     */
    public function relations(): array
    {
        return [
            Related::make('articles', ArticleResource::class)->label('Статьи рубрики')->expandable(),
            Related::make('children', self::class)->label('Подрубрики')->expandable(),
        ];
    }
}
