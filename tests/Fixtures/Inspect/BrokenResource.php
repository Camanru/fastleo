<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Inspect;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Раздел с ошибкой описания: два поля с одним именем.
 */
final class BrokenResource extends Resource
{
    protected static string $model = Category::class;

    /**
     * Дубль имени `name` — схема формы не соберётся.
     */
    public function fields(): array
    {
        return [Text::make('name'), Text::make('name')];
    }
}
