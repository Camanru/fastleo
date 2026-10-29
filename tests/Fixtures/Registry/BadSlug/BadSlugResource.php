<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Registry\BadSlug;

use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Адрес раздела с недопустимыми символами.
 */
final class BadSlugResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'Bad_Slug';

    /**
     * Без полей.
     */
    public function fields(): array
    {
        return [];
    }
}
