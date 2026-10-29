<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Lists;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Категории простым списком (без дерева и ручного порядка) — для тестов поиска, сортировки и страниц.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    /**
     * Название и код.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required()->searchable()->sortable(),
            Text::make('code', 'Код')->maxLength(50)->searchable()->sortable(),
        ];
    }
}
