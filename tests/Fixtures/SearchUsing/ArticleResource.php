<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\SearchUsing;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Support\ContainsExpression;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Article;

/**
 * Статьи со своим условием поиска: заголовок хранит цифры (как телефон клиента), рубрика ищется по названию.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    /** Сколько раз вызван хук поля `hidden_code` (скрытое поле хук не вызывает). */
    public static int $hiddenCalls = 0;

    public function fields(): array
    {
        return [
            Text::make('title', 'Телефон')->searchUsing(function (Builder $query, string $term, string $column): void {
                $digits = preg_replace('/\D/', '', $term) ?? '';
                $digits = str_starts_with($digits, '8') ? '7'.substr($digits, 1) : $digits;

                if (strlen($digits) >= 4) {
                    $query->where($column, 'like', '%'.$digits.'%');
                } else {
                    ContainsExpression::where($query, $column, $term);
                }
            }),
            BelongsTo::make('category_id', 'Рубрика')->searchUsing(
                fn (Builder $query, string $term) => $query->whereHas('category', fn (Builder $category) => ContainsExpression::where($category, 'title', $term)),
            ),
            Text::make('slug', 'Код')->hidden()->searchUsing(function (): void {
                self::$hiddenCalls++;
            }),
        ];
    }
}
