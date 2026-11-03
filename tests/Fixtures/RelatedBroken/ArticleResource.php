<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RelatedBroken;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи — дочерний раздел для проверок ошибок описания связанных таблиц.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'articles';

    /** Описать раздел с ошибкой: два поля с одним именем. */
    public static bool $duplicate = false;

    /**
     * Заголовок.
     */
    public function fields(): array
    {
        return self::$duplicate ? [Text::make('title'), Text::make('title')] : [Text::make('title')->required()];
    }
}
