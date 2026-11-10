<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Summary;

use Fastleo\Fields\Text;
use Fastleo\Related;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Рубрика с карточкой над формой («Первая статья») и таблицей статей, которые правятся в окне.
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
     * Карточка над формой.
     */
    public function summaryView(): string
    {
        return 'summaryfix::card';
    }

    /**
     * Статьи рубрики: правка и «Добавить» — в окне.
     */
    public function relations(): array
    {
        return [Related::make('articles', ArticleResource::class)->label('Статьи')->editIn('modal')];
    }
}
