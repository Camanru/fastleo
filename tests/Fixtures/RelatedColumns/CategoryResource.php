<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RelatedColumns;

use Fastleo\Fields\Text;
use Fastleo\Related;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Рубрики с таблицей статей, в которой столбцы заданы `Related::columns()`; какие именно — `$columns`.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'categories';

    /** @var list<string> */
    public static array $columns = ['status', 'title'];

    /**
     * Название рубрики.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Название')->required()];
    }

    /**
     * Статьи рубрики с выбранными столбцами.
     */
    public function relations(): array
    {
        return [Related::make('articles', ArticleResource::class)->columns(self::$columns)];
    }
}
