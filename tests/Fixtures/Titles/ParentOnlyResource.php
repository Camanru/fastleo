<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Titles;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Категории, в списке которых одна связь с родителем: столбца-названия нет, заголовком станет сама связь (или ключ).
 */
final class ParentOnlyResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'parent-only';

    protected static ?string $label = 'Только связь';

    /**
     * Название есть в форме и поиске, но не в списке.
     */
    public function fields(): array
    {
        return [
            BelongsTo::make('parent_id', 'Родитель'),
            Text::make('title', 'Название')->required()->searchable()->hideInList(),
        ];
    }
}
