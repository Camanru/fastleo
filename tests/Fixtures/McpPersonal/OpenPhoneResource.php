<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\McpPersonal;

use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Телефон есть, раздел открыт агенту (данные людей сами раздел не закрывают).
 */
final class OpenPhoneResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'open-phone';

    public function fields(): array
    {
        return [Text::make('title'), Tel::make('contact')];
    }
}
