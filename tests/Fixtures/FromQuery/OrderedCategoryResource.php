<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\FromQuery;

use Fastleo\Fields\Number;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Category;

/**
 * Рубрики, которые по умолчанию идут по вычисляемому столбцу: числу статей, больше — выше.
 */
final class OrderedCategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'ordered-categories';

    protected static bool $exportable = true;

    /** @var array{0: string, 1: 'asc'|'desc'}|null */
    protected static ?array $defaultOrder = ['articles_count', 'desc'];

    /**
     * Название и число статей из запроса.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required(),
            Number::make('articles_count', 'Статей')->fromQuery()->sortable(),
        ];
    }

    /**
     * Число статей рубрики.
     */
    public function query(Builder $query): Builder
    {
        return $query->withCount('articles');
    }
}
