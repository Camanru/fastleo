<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\HiddenFilter;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Text;
use Fastleo\Filters\BelongsToFilter;
use Fastleo\Filters\SelectFilter;
use Fastleo\Resource;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Статьи с фильтрами по статусу и рубрике; рубрика скрыта от пользователя, статус виден.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'articles';

    /** Сколько раз спрашивали, скрыта ли рубрика: описание ресурса без пользователя не должно вызывать замыкание. */
    public static int $hiddenAsked = 0;

    /**
     * Видимый статус и скрытая рубрика.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            Enum::make('status', 'Статус')->enum(ArticleStatus::class)->required(),
            BelongsTo::make('category_id', 'Рубрика')->hidden(function (): bool {
                self::$hiddenAsked++;

                return true;
            }),
        ];
    }

    /**
     * Фильтры по обоим полям.
     */
    public function filters(): array
    {
        return [SelectFilter::make('status'), BelongsToFilter::make('category_id')];
    }
}
