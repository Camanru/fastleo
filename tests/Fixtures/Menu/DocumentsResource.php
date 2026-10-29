<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Menu;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Раздел «Документы» в группе «Контент» для тестов меню.
 */
final class DocumentsResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'documents';

    protected static ?string $label = 'Документы';

    protected static ?string $group = 'Контент';

    protected static int $position = 20;

    /**
     * Название.
     */
    public function fields(): array
    {
        return [Text::make('title')];
    }
}
