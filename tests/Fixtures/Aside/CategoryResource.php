<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Aside;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Related;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Рубрики: справа от формы — панель истории и статьи рубрики, подрубрики — под формой.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'categories';

    protected static bool $historyPanel = true;

    /**
     * Название и родитель.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required()->searchable(),
            BelongsTo::make('parent_id', 'Родитель'),
        ];
    }

    /**
     * Статьи — в боковой колонке, подрубрики — в основной.
     */
    public function relations(): array
    {
        return [
            Related::make('articles', ArticleResource::class)->label('Статьи рубрики')->aside(),
            Related::make('children', self::class)->label('Подрубрики'),
        ];
    }
}
