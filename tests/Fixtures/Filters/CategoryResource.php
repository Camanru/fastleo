<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Filters;

use Fastleo\Fields\Text;
use Fastleo\Filters\SelectFilter;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Категории деревом с ручным порядком и фильтром по коду — фильтр выключает дерево.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $treeColumn = 'parent_id';

    protected static ?string $sortColumn = 'sort';

    /**
     * Название и код.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required(),
            Text::make('code', 'Код'),
        ];
    }

    /**
     * Фильтр по коду.
     */
    public function filters(): array
    {
        return [SelectFilter::make('code')->options(['a' => 'A', 'b' => 'B'])];
    }
}
