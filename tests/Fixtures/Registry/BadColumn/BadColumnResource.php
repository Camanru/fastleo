<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Registry\BadColumn;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Колонка ручного порядка с недопустимыми символами.
 */
final class BadColumnResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'bad-column';

    protected static ?string $sortColumn = 'sort order; drop';

    /**
     * Одно поле.
     */
    public function fields(): array
    {
        return [Text::make('title')];
    }
}
