<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RelatedOrder;

use Fastleo\Fields\Text;
use Fastleo\Related;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи — родитель таблицы полезных ссылок с ручным порядком.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'articles';

    /** Дочерний раздел связи «links»: тесты подменяют его, чтобы проверить раздел без сортировки и с деревом. */
    public static string $child = ArticleLinkResource::class;

    /**
     * Название.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Заголовок')->required()];
    }

    /**
     * Ссылки статьи таблицей под формой.
     */
    public function relations(): array
    {
        return [Related::make('links', self::$child)];
    }
}
