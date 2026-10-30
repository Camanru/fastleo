<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\McpPersonal;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Обычное текстовое поле с именем `client_email` — по имени раздел закрывается от MCP сам.
 */
final class EmailColumnResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'email-column';

    public function fields(): array
    {
        return [Text::make('title'), Text::make('client_email')];
    }
}
