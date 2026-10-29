<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Resources;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Ресурс разошёлся с моделью: поле internal_code не входит в $fillable категории.
 */
final class GuardedCategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'guarded-categories';

    /**
     * Название и запрещённое моделью поле.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            Text::make('internal_code'),
        ];
    }
}
