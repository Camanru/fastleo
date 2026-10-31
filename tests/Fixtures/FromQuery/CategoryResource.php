<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\FromQuery;

use Fastleo\Fields\Number;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Category;

/**
 * Рубрики с вычисляемыми столбцами: число статей и сумма просмотров приходят из query(), а один столбец не посчитан.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'categories';

    protected static bool $exportable = true;

    /**
     * Название, два вычисляемых столбца и один без значения в запросе.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required()->sortable(),
            Number::make('articles_count', 'Статей')->fromQuery()->sortable(),
            Number::make('views_total', 'Просмотров')->fromQuery()->sortable()->formatUsing(fn (mixed $value): string => number_format((float) $value, 0, ',', ' ').' пр.'),
            Number::make('not_counted', 'Не посчитано')->fromQuery(),
        ];
    }

    /**
     * Подсчёты по статьям рубрики.
     */
    public function query(Builder $query): Builder
    {
        return $query->withCount('articles')->withSum('articles as views_total', 'views');
    }
}
