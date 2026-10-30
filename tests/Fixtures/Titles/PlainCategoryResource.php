<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Titles;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Категории без своего `title()`: заголовком станет первый столбец списка — связь с родителем.
 */
final class PlainCategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'plain-categories';

    protected static ?string $label = 'Простые категории';

    /**
     * Родитель — связь, показывается ссылкой.
     */
    public function fields(): array
    {
        return [
            BelongsTo::make('parent_id', 'Родитель'),
            Text::make('title', 'Название')->required()->searchable(),
        ];
    }
}
