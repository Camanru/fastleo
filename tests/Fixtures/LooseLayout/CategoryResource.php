<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\LooseLayout;

use Fastleo\Fields\Text;
use Fastleo\Layout\Section;
use Fastleo\Layout\Tab;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Рубрики: вкладка без `Tabs` и раскрытая сворачиваемая секция.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'categories';

    /**
     * Вкладка с заголовком прямо в форме и секция с `collapsible()`.
     */
    public function fields(): array
    {
        return [
            Tab::make('Основное', [Text::make('title', 'Заголовок')->required()]),
            Section::make('Дополнительно', [Text::make('slug', 'Адрес')])->collapsible(),
        ];
    }
}
