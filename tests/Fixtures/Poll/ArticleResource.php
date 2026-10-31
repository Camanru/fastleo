<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Poll;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Number;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи, список которых обновляется сам раз в 5 секунд; просмотры правятся в ячейке.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'poll-articles';

    protected static ?int $poll = 5;

    /**
     * Заголовок и просмотры.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            Number::make('views', 'Просмотры')->integer()->min(0)->inlineEditable(),
            BelongsTo::make('category_id', 'Рубрика'),
        ];
    }
}
