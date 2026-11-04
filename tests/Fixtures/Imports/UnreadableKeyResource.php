<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Imports;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Категории со своим ключом сопоставления, которого нет в таблице: база не может сравнить с ним значение
 * (на PostgreSQL так же падает числовой ключ со словом в ячейке).
 */
final class UnreadableKeyResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'unreadable-key';

    protected static bool $importable = true;

    protected static ?string $importKey = 'external_id';

    /**
     * Название.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required(),
        ];
    }
}
