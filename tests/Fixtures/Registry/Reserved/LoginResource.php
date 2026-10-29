<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Registry\Reserved;

use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Ресурс занимает адрес страницы входа.
 */
final class LoginResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'login';

    /**
     * Без полей.
     */
    public function fields(): array
    {
        return [];
    }
}
