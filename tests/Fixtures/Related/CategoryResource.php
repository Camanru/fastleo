<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Related;

use Fastleo\Fields\Text;
use Fastleo\Related;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Рубрики со связанной таблицей статей (по две на страницу).
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'categories';

    /**
     * Название и код рубрики.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required(),
            Text::make('code', 'Код')->maxLength(50)->hideInList(),
        ];
    }

    /**
     * Статьи рубрики под формой.
     */
    public function relations(): array
    {
        return [Related::make('articles', ArticleResource::class)->label('Статьи рубрики')->perPage(2)];
    }
}
