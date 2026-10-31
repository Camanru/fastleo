<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RelatedColumns;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Статьи — дочерний раздел: заголовок, статус, рубрика и заметка, которой нет в списке.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'articles';

    /**
     * Поля списка и формы.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            Enum::make('status', 'Статус')->enum(ArticleStatus::class)->badges(),
            BelongsTo::make('category_id', 'Рубрика')->required(),
            Text::make('excerpt', 'Заметка')->hideInList(),
        ];
    }
}
