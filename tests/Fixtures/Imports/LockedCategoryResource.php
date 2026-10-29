<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Imports;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Категории с кодом из внешней системы: по коду строки находятся, но сам код не меняется.
 */
final class LockedCategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'locked-categories';

    protected static bool $importable = true;

    protected static ?string $importKey = 'code';

    /**
     * Название и код только для чтения.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required(),
            Text::make('code', 'Код')->readonly(),
        ];
    }
}
