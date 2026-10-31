<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Expand;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\ArticleLink;

/**
 * Ссылки статьи: самый глубокий уровень.
 */
final class LinkResource extends Resource
{
    protected static string $model = ArticleLink::class;

    protected static ?string $slug = 'links';

    /**
     * Название ссылки и статья.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название ссылки')->required(),
            BelongsTo::make('article_id', 'Статья')->required(),
        ];
    }
}
