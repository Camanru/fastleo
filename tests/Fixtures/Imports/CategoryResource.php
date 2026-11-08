<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Imports;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Override;
use Workbench\App\Models\Category;

/**
 * Категории: строки сопоставляются по коду, новые встают в конец ручного порядка.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static bool $importable = true;

    protected static ?string $importKey = 'code';

    protected static ?string $sortColumn = 'sort';

    /**
     * Запись с кодом «secret» раздел не показывает.
     *
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    #[Override]
    public function query(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner): Builder => $inner->whereNull('code')->orWhere('code', '!=', 'secret'));
    }

    /**
     * Название и код.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required(),
            Text::make('code', 'Код')->maxLength(50),
        ];
    }
}
