<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\FilterDefaults;

use Fastleo\Fields\Enum;
use Fastleo\Fields\Text;
use Fastleo\Filters\SelectFilter;
use Fastleo\Resource;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Статьи, список которых открывается с фильтром «Статус = Опубликовано».
 */
final class StatusDefaultResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'status-default';

    /**
     * Заголовок для поиска и статус для фильтра.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required()->searchable(),
            Enum::make('status', 'Статус')->enum(ArticleStatus::class)->required(),
        ];
    }

    /**
     * Фильтр с умолчанием.
     */
    public function filters(): array
    {
        return [SelectFilter::make('status')->default('published')];
    }
}
