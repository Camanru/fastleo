<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\McpActions;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи с действием, закрытым для агента MCP, и действием, которое отклоняет база.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    /**
     * Заголовок.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Заголовок')->required()];
    }

    /**
     * Действие только для людей.
     */
    public function actions(): array
    {
        return [new HideFromAgent, new RefusedByDatabase];
    }
}
