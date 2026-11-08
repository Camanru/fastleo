<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\TwoSections;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Второй по алфавиту раздел категорий.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'categories';

    protected static ?string $label = 'Категории';

    /**
     * Одно поле.
     */
    public function fields(): array
    {
        return [Text::make('title')->required()];
    }
}
