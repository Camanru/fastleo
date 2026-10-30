<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\McpPersonal;

use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Поле типа Tel без `$mcpPersonalData` — раздел закрывается от MCP сам.
 */
final class PhoneFieldResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'phone-field';

    public function fields(): array
    {
        return [Text::make('title'), Tel::make('contact')];
    }
}
