<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\McpPersonal;

use Fastleo\Fields\Repeater;
use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Телефон внутри повторителя — раздел закрывается от MCP сам.
 */
final class NestedPhoneResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'nested-phone';

    public function fields(): array
    {
        return [
            Text::make('title'),
            Repeater::make('contacts')->fields([Text::make('label'), Tel::make('number')]),
        ];
    }
}
