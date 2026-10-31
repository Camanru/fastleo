<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RelatedBroken;

use Fastleo\Fields\HasMany;
use Fastleo\Fields\Text;
use Fastleo\Related;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Рубрики, у которых связанная таблица описана неверно; что именно — задаёт `$mode`.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'categories';

    /** Вид ошибки: not-has-many, no-method, no-resource, wrong-model, duplicate, conflict, create-only. */
    public static string $mode = 'conflict';

    /**
     * Название; в режиме conflict/create-only ещё и вложенные статьи.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            ...match (self::$mode) {
                'conflict' => [HasMany::make('articles')->fields([Text::make('title')])],
                'create-only' => [HasMany::make('articles')->fields([Text::make('title')])->hideOnEdit()],
                default => [],
            },
        ];
    }

    /**
     * Связанные таблицы с ошибкой по режиму.
     */
    public function relations(): array
    {
        return match (self::$mode) {
            'not-has-many' => [Related::make('parent', ArticleResource::class)],
            'no-method' => [Related::make('missing', ArticleResource::class)],
            'no-resource' => [Related::make('articles', Text::class)],
            'wrong-model' => [Related::make('children', ArticleResource::class)],
            'duplicate' => [Related::make('articles', ArticleResource::class), Related::make('articles', ArticleResource::class)],
            default => [Related::make('articles', ArticleResource::class)],
        };
    }
}
