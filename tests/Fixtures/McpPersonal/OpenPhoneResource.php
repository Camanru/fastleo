<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\McpPersonal;

use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Телефон есть, но сайт открыл раздел агенту явно.
 */
final class OpenPhoneResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'open-phone';

    protected static bool $mcpPersonalData = true;

    public function fields(): array
    {
        return [Text::make('title'), Tel::make('contact')];
    }
}
