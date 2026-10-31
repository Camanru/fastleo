<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\InlineScoped;

use Fastleo\Fields\Enum;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Только черновики: после правки статуса в ячейке запись выпадает из выборки раздела.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'draft-articles';

    /**
     * Заголовок и статус, который правится в ячейке.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            Enum::make('status', 'Статус')->enum(ArticleStatus::class)->required()->inlineEditable(),
        ];
    }

    /**
     * Только черновики.
     */
    public function query(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }
}
