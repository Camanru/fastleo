<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\CreateLabel;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи с собственной подписью кнопки создания.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'articles';

    protected static ?string $createLabel = 'Добавить заявку';

    /**
     * Заголовок статьи.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Заголовок')->required()];
    }
}
