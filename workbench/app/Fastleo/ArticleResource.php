<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Checkbox;
use Fastleo\Fields\Code;
use Fastleo\Fields\Color;
use Fastleo\Fields\Date;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Email;
use Fastleo\Fields\Enum;
use Fastleo\Fields\File;
use Fastleo\Fields\Gallery;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\HasOne;
use Fastleo\Fields\Hidden;
use Fastleo\Fields\Image;
use Fastleo\Fields\Markdown;
use Fastleo\Fields\Number;
use Fastleo\Fields\Radio;
use Fastleo\Fields\Range;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Select;
use Fastleo\Fields\Seo;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Tags;
use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Time;
use Fastleo\Fields\Toggle;
use Fastleo\Fields\Url;
use Fastleo\Filters\BelongsToFilter;
use Fastleo\Filters\BelongsToManyFilter;
use Fastleo\Filters\BooleanFilter;
use Fastleo\Filters\DateRangeFilter;
use Fastleo\Filters\SelectFilter;
use Fastleo\Layout\Aside;
use Fastleo\Layout\Grid;
use Fastleo\Layout\Section;
use Fastleo\Layout\Tab;
use Fastleo\Layout\Tabs;
use Fastleo\Publishing\Post;
use Fastleo\Publishing\Publish;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Fastleo\Actions\PublishArticles;
use Workbench\App\Fastleo\Actions\SchedulePublication;
use Workbench\App\Models\Article;

/**
 * Раздел «Статьи» демо-сайта: все базовые поля, вкладки, секции, сетка, боковая панель, корзина.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static bool $importable = true;

    protected static ?string $label = 'Статьи';

    protected static ?string $singular = 'Статья';

    protected static ?string $description = 'Новости и материалы блога.';

    protected static string $icon = 'newspaper';

    protected static ?string $group = 'Контент';

    protected static int $position = 10;

    /**
     * Поля статьи по вкладкам.
     */
    public function fields(): array
    {
        return [
            Hidden::make('type')->default('news'),
            Tabs::make([
                Tab::make('Основное', [
                    Text::make('title', 'Заголовок')->required()->searchable()->sortable(),
                    Slug::make('slug', 'Адрес')->from('title')->help('Заполняется из заголовка; можно изменить')->hideInList(),
                    BelongsTo::make('category_id', 'Категория'),
                    BelongsTo::make('subcategory_id', 'Подраздел')
                        ->dependsOn('category_id', fn (Builder $query, mixed $category) => $query->where('parent_id', $category ?? 0))
                        ->help('Вложенная категория выбранной категории')
                        ->hideInList(),
                    BelongsToMany::make('topics', 'Темы')->hideInList(),
                    Textarea::make('excerpt', 'Анонс')->rows(4)->maxLength(1000)->searchable(),
                    Grid::make(3, [
                        Enum::make('status', 'Статус')->enum(ArticleStatus::class)->required()->sortable(),
                        Number::make('views', 'Просмотры')->integer()->min(0)->default(0)->sortable(),
                        Number::make('price', 'Цена, ₽')->min(0)->step(0.01)->hideInList(),
                    ]),
                    Select::make('tags', 'Метки')->multiple()->options([
                        'news' => 'Новости',
                        'sale' => 'Акции',
                        'guide' => 'Советы',
                    ])->hideInList(),
                    Tags::make('keywords', 'Ключевые слова')->suggestions(['акция', 'новинка', 'обзор'])->hideInList(),
                ]),
                Tab::make('Содержание', [
                    RichText::make('content', 'Текст статьи'),
                    Markdown::make('body', 'Краткое описание (Markdown)')->hideInList(),
                    Code::make('head_code', 'Код в <head>')->rows(6)->help('Вставляется на страницу статьи без изменений')->hideInList(),
                ]),
                Tab::make('Медиа', [
                    Image::make('cover', 'Обложка')->directory('articles')->crop('16:9')->variants('card'),
                    Gallery::make('gallery', 'Фотогалерея')->directory('articles')->max(20)->variants('photo')->hideInList(),
                    File::make('attachment', 'Файл для скачивания')->extensions(['pdf', 'docx', 'xlsx'])->directory('documents')->hideInList(),
                ]),
                Tab::make('Ссылки', [
                    HasMany::make('links', 'Полезные ссылки')
                        ->fields([
                            Grid::make(2, [
                                Text::make('title', 'Название')->required(),
                                Url::make('url', 'Адрес'),
                            ]),
                        ])
                        ->orderColumn('sort')
                        ->max(10),
                    HasOne::make('banner', 'Баннер')->fields([
                        Grid::make(2, [
                            Text::make('title', 'Текст баннера'),
                            Url::make('link', 'Ссылка'),
                        ]),
                        Image::make('image', 'Картинка баннера')->directory('banners')->variants('card'),
                        Toggle::make('is_active', 'Показывать')->default(true),
                    ]),
                ]),
                Tab::make('Контакты', [
                    Section::make('Автор', [
                        Grid::make(2, [
                            Email::make('author_email', 'Email автора')->searchable()->hideInList(),
                            Tel::make('phone', 'Телефон')->placeholder('+7 900 000-00-00')->hideInList(),
                        ]),
                        Url::make('source_url', 'Источник')->help('Адрес страницы, откуда взят материал')->hideInList(),
                    ])->description('Не показывается на сайте'),
                    Section::make('Оформление', [
                        Color::make('color', 'Цвет карточки')->hideInList(),
                    ])->collapsed(),
                ]),
                Tab::make('SEO', [
                    Seo::make('seo')->directory('articles'),
                ]),
            ]),
            Aside::make([
                Toggle::make('is_published', 'Опубликовано')->sortable()->hideInList(),
                Radio::make('layout', 'Вид страницы')->options(['wide' => 'Широкий', 'narrow' => 'Узкий'])->default('wide')->hideInList(),
                Range::make('rating', 'Оценка редакции')->max(10)->default(0)->hideInList(),
                Checkbox::make('is_featured', 'На главной')->hideInList(),
                Date::make('published_on', 'Дата публикации')->sortable(),
                DateTime::make('starts_at', 'На главной с')->visibleWhen('is_featured', true)->hideInList(),
                Time::make('opens_at', 'Время открытия')->hideInList(),
            ]),
        ];
    }

    /**
     * Фильтры списка статей.
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
            SelectFilter::make('popularity', 'Популярность')
                ->options(['popular' => 'Больше 1000 просмотров', 'quiet' => 'До 100 просмотров'])
                ->using(fn (Builder $query, string $value) => $value === 'popular' ? $query->where('views', '>', 1000) : $query->where('views', '<=', 100)),
        ];
    }

    /**
     * Действия над статьёй и выбранными статьями.
     */
    public function actions(): array
    {
        return [
            new PublishArticles,
            new SchedulePublication,
            Publish::make(fn (Article $article): Post => Post::make('**'.$article->title.'**'.($article->excerpt === null ? '' : "\n\n".$article->excerpt))
                ->images([$article->cover])
                ->button('Читать на сайте', route('articles.show', $article->id))),
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
     * В карту сайта идут только опубликованные статьи.
     */
    public function sitemap(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}
