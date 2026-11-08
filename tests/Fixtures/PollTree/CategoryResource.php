<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\PollTree;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Рубрики деревом, список которых обновляется сам раз в 5 секунд.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'poll-tree';

    protected static ?string $treeColumn = 'parent_id';

    protected static ?int $poll = 5;

    /**
     * Название.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Название')->required()];
    }
}
