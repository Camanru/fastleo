<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\TwoSections;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Первый по алфавиту раздел категорий: у модели Category их два (этот и CategoryResource).
 */
final class ArchivedCategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'archived-categories';

    protected static ?string $label = 'Архив категорий';

    /**
     * Одно поле.
     */
    public function fields(): array
    {
        return [Text::make('title')->required()];
    }
}
