<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Layout\Tab;
use Fastleo\Layout\Tabs;
use Fastleo\Related;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Раздел «Категории» демо-сайта: дерево, вкладки с таблицами статей и товаров.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $label = 'Категории';

    protected static ?string $singular = 'Категория';

    protected static string $icon = 'folder-open';

    protected static ?string $group = 'Контент';

    protected static int $position = 20;

    protected static ?string $treeColumn = 'parent_id';

    protected static ?string $sortColumn = 'sort';

    /**
     * Вкладки: поля категории, затем таблицы связанных записей. Статьи правятся на своей странице (`editIn('page')`),
     * товары — в окне (`editIn('modal')`); таблицы вне формы и не сохраняются вместе с ней.
     */
    public function fields(): array
    {
        return [
            Tabs::make([
                Tab::make('Основное', [
                    Text::make('title', 'Название')->required()->searchable()->sortable(),
                    BelongsTo::make('parent_id', 'Родитель')->hideInList(),
                    Text::make('code', 'Код')->maxLength(50)->help('Латиница и дефис, например news')->searchable()->sortable(),
                ]),
                Tab::make('Статьи', [
                    Related::make('articles', ArticleResource::class)->perPage(5)->columns(['title', 'status'])->editIn('page'),
                ]),
                Tab::make('Товары', [
                    Related::make('products', ProductResource::class)->perPage(5)->editIn('modal'),
                ]),
            ]),
        ];
    }
}
