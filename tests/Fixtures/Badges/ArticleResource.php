<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Badges;

use Fastleo\Fields\Enum;
use Fastleo\Fields\Select;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Статьи с цветными метками: тон из enum, тон из массива (одиночный и множественный список) и поле со своим выводом.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'badges';

    /**
     * Поля с метками и поле, у которого метки отключает собственный `formatUsing()`.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            Enum::make('status', 'Статус')->enum(ArticleStatus::class)->badges(),
            Select::make('type', 'Вид')->options(['news' => 'Новость', 'promo' => 'Акция'])->badges(['news' => 'info']),
            Select::make('tags', 'Метки')->multiple()->options(['news' => 'Новости', 'promo' => 'Акции'])->badges(['news' => 'success', 'promo' => 'danger']),
            Select::make('layout', 'Вид страницы')->options(['wide' => 'Широкий'])->badges(['wide' => 'info'])->formatUsing(fn (mixed $value): string => 'свой '.$value),
        ];
    }
}
