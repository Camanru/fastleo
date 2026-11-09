<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\ListUx;

use Fastleo\Fields\Text;
use Fastleo\Related;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Category;

/**
 * Рубрики: рубрику «Закрытая» нельзя изменить, поэтому нельзя и привязать к ней статью. Статьи показаны без кнопки «Добавить».
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'categories';

    /**
     * Название рубрики.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Название')->required()];
    }

    /**
     * Изменять «Закрытую» нельзя.
     */
    public function can(string $ability, ?Model $record): bool
    {
        return $ability !== 'update' || $record?->getAttribute('title') !== 'Закрытая';
    }

    /**
     * Статьи рубрики без кнопки «Добавить» над таблицей, по две на странице, без строки «Записи 1–2 из 2» на единственной странице.
     */
    public function relations(): array
    {
        return [Related::make('articles', ArticleResource::class)->withoutAddButton()->perPage(2)->withoutSinglePageFooter()];
    }
}
