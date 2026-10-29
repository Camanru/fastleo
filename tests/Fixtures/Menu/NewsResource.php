<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Menu;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Раздел «Новости» в группе «Контент» для тестов меню.
 */
final class NewsResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'news';

    protected static ?string $label = 'Новости';

    protected static ?string $group = 'Контент';

    protected static int $position = 10;

    /**
     * Новых записей нет — ноль.
     */
    public static function badge(): int
    {
        return 0;
    }

    /**
     * Название.
     */
    public function fields(): array
    {
        return [Text::make('title')];
    }
}
