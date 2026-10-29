<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Blocks;

use Fastleo\Fields\HasMany;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи, у которых должна быть хотя бы одна ссылка.
 */
final class LinkedArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'linked-articles';

    /**
     * Заголовок и не меньше одной ссылки.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            HasMany::make('links', 'Ссылки')->min(1)->fields([Text::make('title', 'Название')->required()]),
        ];
    }
}
