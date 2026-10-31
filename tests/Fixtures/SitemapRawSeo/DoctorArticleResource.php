<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\SitemapRawSeo;

use Fastleo\Fields\Seo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\DoctorArticle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Статьи без каста колонки `seo`: модель отдаёт её JSON-строкой, а не массивом.
 */
final class DoctorArticleResource extends Resource
{
    protected static string $model = DoctorArticle::class;

    /**
     * Заголовок и мета-теги.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            Seo::make('seo')->directory('articles'),
        ];
    }

    /**
     * Адрес статьи на сайте.
     */
    public function url(Model $record): string
    {
        return route('articles.show', $record->getKey());
    }

    /**
     * Все статьи, без условий.
     */
    public function sitemap(Builder $query): Builder
    {
        return $query;
    }
}
