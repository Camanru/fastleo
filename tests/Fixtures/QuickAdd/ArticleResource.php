<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\QuickAdd;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Hidden;
use Fastleo\Fields\Number;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Статьи — дочерний раздел рубрик для быстрого добавления: заголовок, статус и тип с умолчаниями, адрес из заголовка, рубрика,
 * число просмотров и поля, которые в быстрое добавление не годятся.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'articles';

    protected static bool $activity = true;

    /** Как описан внешний ключ рубрики: normal, readonly или absent (поля нет в форме). */
    public static string $foreignMode = 'normal';

    /** Почта администратора, от которого скрыто поле «Просмотры»; null — скрыто ни от кого. */
    public static ?string $hideViewsFrom = null;

    /**
     * Поля формы. Умолчания статуса и типа отличаются от умолчаний колонок, чтобы было видно, откуда взято значение.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required()->searchable(),
            Slug::make('slug', 'Адрес')->from('title'),
            Enum::make('status', 'Статус')->enum(ArticleStatus::class)->required()->default('published'),
            Hidden::make('type')->default('event'),
            ...match (self::$foreignMode) {
                'readonly' => [BelongsTo::make('category_id', 'Рубрика')->readonly()],
                'absent' => [],
                default => [BelongsTo::make('category_id', 'Рубрика')->required()],
            },
            Number::make('views', 'Просмотры')->integer()->min(0)->emptyValue(0)
                ->hidden(fn (): bool => self::$hideViewsFrom !== null && auth('fastleo')->user()?->getAttribute('email') === self::$hideViewsFrom),
            Text::make('excerpt', 'Пометка')->visibleWhen('status', 'draft'),
            Text::make('source_url', 'Источник')->translatable(),
            Toggle::make('is_featured', 'Важная')->hideInList(),
        ];
    }
}
