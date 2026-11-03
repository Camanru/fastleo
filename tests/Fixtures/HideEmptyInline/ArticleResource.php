<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\HideEmptyInline;

use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи с телефоном, который правится в ячейке: столбец не исчезает, даже когда пуст.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    /**
     * Название и телефон с правкой на месте и `hideWhenEmpty()`.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок'),
            Tel::make('phone', 'Телефон')->inlineEditable()->hideWhenEmpty(),
        ];
    }
}
