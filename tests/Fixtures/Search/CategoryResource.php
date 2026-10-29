<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Search;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Категории, которые находит глобальный поиск.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $label = 'Категории';

    protected static ?string $singular = 'Категория';

    /**
     * Название и код.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required()->searchable(),
            Text::make('code', 'Код')->maxLength(50)->searchable(),
        ];
    }
}
