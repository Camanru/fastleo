<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Titles;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Category;

/**
 * Категории, где первым столбцом стоит связь с родителем, а название записи задаёт `title()`.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $treeColumn = 'parent_id';

    /**
     * Родитель идёт первым, название — вторым.
     */
    public function fields(): array
    {
        return [
            BelongsTo::make('parent_id', 'Родитель'),
            Text::make('title', 'Название')->required()->searchable(),
        ];
    }

    /**
     * Название записи — заголовок категории, а не её родитель.
     */
    #[\Override]
    public function title(Model $record): string
    {
        return 'Раздел «'.$record->getAttribute('title').'»';
    }
}
