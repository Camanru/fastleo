<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\McpPersonal;

use Fastleo\Fields\Block;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Телефон внутри блока конструктора — раздел закрывается от MCP сам.
 */
final class BlockPhoneResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'block-phone';

    public function fields(): array
    {
        return [
            Text::make('title'),
            Blocks::make('content')->blocks([Block::make('call')->fields([Tel::make('number')])]),
        ];
    }
}
