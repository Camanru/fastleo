<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Expand;

use Fastleo\Fields\Text;
use Fastleo\Related;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Рубрики с ошибкой описания: строки раскрываются, а у статей нет своих связанных таблиц.
 */
final class BareResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'bare';

    /**
     * Название.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Название')->required()];
    }

    /**
     * Раскрывать нечего: у PlainArticleResource нет relations().
     */
    public function relations(): array
    {
        return [Related::make('articles', PlainArticleResource::class)->expandable()];
    }
}
