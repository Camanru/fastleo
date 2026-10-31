<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RowTone;

use Fastleo\Fields\Text;
use Fastleo\Related;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Рубрики со связанной таблицей статей, у которых строки подсвечены.
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
        return [Text::make('title', 'Название')->required()];
    }

    /**
     * Статьи рубрики.
     */
    public function relations(): array
    {
        return [Related::make('articles', ArticleResource::class)];
    }
}
