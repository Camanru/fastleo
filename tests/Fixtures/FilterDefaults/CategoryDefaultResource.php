<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\FilterDefaults;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Filters\BelongsToFilter;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи, список которых открывается с категорией, заданной ЧИСЛОМ (как `auth()->id()` в «менеджер — я»).
 */
final class CategoryDefaultResource extends Resource
{
    /** Ключ категории по умолчанию: число, не строка. */
    public static int $categoryId = 0;

    protected static string $model = Article::class;

    protected static ?string $slug = 'category-default';

    /**
     * Заголовок и категория.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            BelongsTo::make('category_id', 'Категория'),
        ];
    }

    /**
     * Фильтр с числовым умолчанием.
     */
    public function filters(): array
    {
        return [BelongsToFilter::make('category_id')->default(fn (): int => self::$categoryId)];
    }
}
