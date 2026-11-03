<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\OneColumn;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи с одним столбцом: выбирать столбцы не из чего.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    /**
     * Только название.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Заголовок')];
    }
}
