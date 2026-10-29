<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Exports;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Date;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Gallery;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\Number;
use Fastleo\Fields\Select;
use Fastleo\Fields\Tags;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Toggle;
use Fastleo\Filters\BooleanFilter;
use Fastleo\Resource;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Статьи с полями разных типов — для тестов выгрузки и загрузки.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    /**
     * Поля всех видов значений.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required()->searchable(),
            Enum::make('status', 'Статус')->enum(ArticleStatus::class)->required(),
            BelongsTo::make('category_id', 'Категория'),
            BelongsToMany::make('topics', 'Темы'),
            Toggle::make('is_published', 'Опубликовано'),
            Select::make('tags', 'Метки')->multiple()->options(['news' => 'Новости', 'promo' => 'Акции']),
            Tags::make('keywords', 'Ключевые слова'),
            Number::make('views', 'Просмотры')->integer(),
            Number::make('price', 'Цена'),
            Date::make('published_on', 'Дата публикации'),
            DateTime::make('starts_at', 'На главной с'),
            Gallery::make('gallery', 'Галерея'),
            Textarea::make('excerpt', 'Анонс')->exportable(false),
            Text::make('author_email', 'Email')->hidden(),
            HasMany::make('links', 'Ссылки')->fields([Text::make('title')]),
        ];
    }

    /**
     * Фильтр для проверки выгрузки отфильтрованного списка.
     */
    public function filters(): array
    {
        return [BooleanFilter::make('is_published')];
    }
}
