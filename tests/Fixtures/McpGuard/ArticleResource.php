<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\McpGuard;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Override;
use Workbench\App\Models\Article;

/**
 * Статьи, из которых «Согласовано» закрыто для агента, с обычным и опасным действием.
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
     * Обычное и опасное действия.
     */
    public function actions(): array
    {
        return [new AppendMark(), new WipeTitles()];
    }

    /**
     * Статья «Согласовано» закрыта для агента.
     */
    #[Override]
    public function isLockedForMcp(Model $record): bool
    {
        return $record->getAttribute('title') === 'Согласовано';
    }
}
