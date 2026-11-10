<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\OwnerOnly;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи, часть возможностей которых закрыта для всех, кроме владельца; что именно — задаёт тест.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'articles';

    /** @var list<string> */
    public static array $ownerOnly = [];

    public static bool $ownerOnlyIndex = false;

    /**
     * Заголовок.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Заголовок')->required()];
    }
}
