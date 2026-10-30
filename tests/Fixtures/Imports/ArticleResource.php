<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Imports;

use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Date;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Number;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Select;
use Fastleo\Fields\Tags;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Toggle;
use Fastleo\Fields\Url;
use Fastleo\Resource;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Статьи, которые можно загружать из файла.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static bool $importable = true;

    /**
     * Поля разных видов значений (в том числе HTML); email только для чтения, ссылка — только у видео.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required()->maxLength(255),
            Enum::make('status', 'Статус')->enum(ArticleStatus::class)->default(ArticleStatus::Draft)->required(),
            Toggle::make('is_published', 'Опубликовано'),
            Select::make('tags', 'Метки')->multiple()->options(['news' => 'Новости', 'promo' => 'Акции']),
            Tags::make('keywords', 'Ключевые слова'),
            Number::make('views', 'Просмотры')->integer()->min(0),
            Date::make('published_on', 'Дата публикации'),
            DateTime::make('starts_at', 'На главной с'),
            BelongsToMany::make('topics', 'Темы'),
            Textarea::make('excerpt', 'Анонс'),
            RichText::make('body', 'Текст'),
            Text::make('author_email', 'Email автора')->readonly(),
            Select::make('type', 'Тип')->options(['text' => 'Текст', 'video' => 'Видео']),
            Url::make('source_url', 'Ссылка на видео')->visibleWhen('type', 'video'),
        ];
    }
}
