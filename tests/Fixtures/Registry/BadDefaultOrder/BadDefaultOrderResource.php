<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Registry\BadDefaultOrder;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Порядок по умолчанию без направления: `['title']` вместо `['title', 'asc']`.
 */
final class BadDefaultOrderResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'bad-default-order';

    /** @var array{0: string, 1: 'asc'|'desc'}|null */
    protected static ?array $defaultOrder = ['title'];

    /**
     * Одно поле.
     */
    public function fields(): array
    {
        return [Text::make('title')];
    }
}
