<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Inline;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Number;
use Fastleo\Fields\Select;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Статьи, у которых цена, просмотры, статус и категория правятся прямо в ячейке; заголовок и автор — нет; «Вдвое» считается в запросе.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'inline-articles';

    /**
     * Поля с правкой в ячейке и без неё.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required()->maxLength(40),
            Number::make('views', 'Просмотры')->integer()->min(0)->required()->inlineEditable(),
            Text::make('excerpt', 'Анонс')->maxLength(15)->inlineEditable(),
            Enum::make('status', 'Статус')->enum(ArticleStatus::class)->badges()->required()->inlineEditable(),
            Select::make('type', 'Вид')->options(['news' => 'Новость', 'promo' => 'Акция'])->required()->inlineEditable(),
            BelongsTo::make('category_id', 'Категория')->inlineEditable(),
            Text::make('author_email', 'Автор')->readonly()->inlineEditable(),
            Number::make('rating', 'Рейтинг')->integer()->min(0)->hideOnEdit()->inlineEditable(),
            Number::make('views_double', 'Вдвое')->fromQuery(),
        ];
    }

    /**
     * Вычисляемый столбец «Вдвое» зависит от правимых «Просмотров».
     */
    public function query(Builder $query): Builder
    {
        return $query->select('articles.*')->selectRaw('views * 2 as views_double');
    }
}
