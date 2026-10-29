<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Раздел «Категории» демо-сайта: простой справочник.
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
     * Название, родитель и код категории.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required()->searchable()->sortable(),
            BelongsTo::make('parent_id', 'Родитель')->hideInList(),
            Text::make('code', 'Код')->maxLength(50)->help('Латиница и дефис, например news')->searchable()->sortable(),
        ];
    }
}
