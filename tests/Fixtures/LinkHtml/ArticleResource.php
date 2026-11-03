<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\LinkHtml;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Support\HtmlString;
use Workbench\App\Models\Article;

/**
 * Статьи, у которых категория выводится своим HTML: оборачивать её в ссылку не нужно.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    /**
     * Название и категория жирным.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок'),
            BelongsTo::make('category_id', 'Категория')->formatUsing(fn (mixed $value): HtmlString => new HtmlString('<b>'.e((string) $value).'</b>')),
        ];
    }
}
