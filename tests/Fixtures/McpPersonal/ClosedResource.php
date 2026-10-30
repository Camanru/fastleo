<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\McpPersonal;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Без данных людей, но закрыт явно (`$mcp = false`).
 */
final class ClosedResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'closed';

    protected static bool $mcp = false;

    public function fields(): array
    {
        return [Text::make('title')];
    }
}
