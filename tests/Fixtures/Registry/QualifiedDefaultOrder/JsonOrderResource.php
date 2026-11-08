<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Registry\QualifiedDefaultOrder;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Порядок по умолчанию по ключу JSON-колонки: `['seo->title', 'asc']`.
 */
final class JsonOrderResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'json-order';

    /** @var array{0: string, 1: 'asc'|'desc'}|null */
    protected static ?array $defaultOrder = ['seo->title', 'asc'];

    /**
     * Одно поле.
     */
    public function fields(): array
    {
        return [Text::make('title')];
    }
}
