<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Registry\QualifiedDefaultOrder;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Порядок по умолчанию по колонке с именем таблицы: `['categories.title', 'desc']`.
 */
final class QualifiedOrderResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'qualified-order';

    /** @var array{0: string, 1: 'asc'|'desc'}|null */
    protected static ?array $defaultOrder = ['categories.title', 'desc'];

    /**
     * Одно поле.
     */
    public function fields(): array
    {
        return [Text::make('title')];
    }
}
