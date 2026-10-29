<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Registry\Duplicate;

use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Первый ресурс с адресом «items».
 */
final class FirstResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'items';

    /**
     * Без полей.
     */
    public function fields(): array
    {
        return [];
    }
}
