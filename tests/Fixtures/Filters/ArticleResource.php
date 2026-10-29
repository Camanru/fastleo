<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Filters;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Date;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Select;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Filters\BelongsToFilter;
use Fastleo\Filters\BelongsToManyFilter;
use Fastleo\Filters\BooleanFilter;
use Fastleo\Filters\DateRangeFilter;
use Fastleo\Filters\SelectFilter;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Статьи со всеми готовыми фильтрами — для тестов фильтров списка.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    /**
     * Поля, от которых фильтры берут подписи и варианты.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required()->searchable(),
            Enum::make('status', 'Статус')->enum(ArticleStatus::class)->required(),
            BelongsTo::make('category_id', 'Категория'),
            Toggle::make('is_published', 'Опубликовано'),
            BelongsToMany::make('topics', 'Тема'),
            Select::make('tags', 'Метки')->multiple()->options(['news' => 'Новости', 'promo' => 'Акции']),
            Date::make('published_on', 'Дата публикации'),
            DateTime::make('starts_at', 'На главной с'),
        ];
    }

    /**
     * Готовые фильтры и свой.
     */
    public function filters(): array
    {
        return [
            SelectFilter::make('status'),
            BelongsToFilter::make('category_id'),
            BelongsToManyFilter::make('topics', 'Тема'),
            BooleanFilter::make('is_published'),
            SelectFilter::make('tags'),
            DateRangeFilter::make('published_on'),
            DateRangeFilter::make('starts_at'),
            DateRangeFilter::make('created_at', 'Создано'),
            SelectFilter::make('popular', 'Популярность')
                ->options(['yes' => 'Больше 1000 просмотров'])
                ->using(fn (Builder $query, string $value) => $query->where('views', '>', 1000)),
        ];
    }
}
