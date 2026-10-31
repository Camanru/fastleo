<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorBroken;

use Fastleo\Fields\Number;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Category;

/**
 * Рубрики с вычисляемым столбцом, а запрос раздела подсчитывает несуществующую связь и падает.
 */
final class BrokenQueryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'broken-query';

    /**
     * Название и столбец, который должен прийти из запроса.
     */
    public function fields(): array
    {
        return [
            Text::make('title'),
            Number::make('articles_count')->fromQuery(),
        ];
    }

    /**
     * Запрос с подсчётом связи, которой у модели нет.
     */
    public function query(Builder $query): Builder
    {
        return $query->withCount('nothing');
    }
}
