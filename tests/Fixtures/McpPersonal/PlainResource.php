<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\McpPersonal;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Без данных людей и без `$mcp`/`$mcpPersonalData` — открыт агенту.
 */
final class PlainResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'plain';

    public function fields(): array
    {
        return [Text::make('title'), Text::make('hotel')];
    }
}
