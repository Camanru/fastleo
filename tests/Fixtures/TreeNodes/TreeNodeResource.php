<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\TreeNodes;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\TreeNode;

/**
 * Дерево со строковыми ключами и корзиной.
 */
final class TreeNodeResource extends Resource
{
    protected static string $model = TreeNode::class;

    protected static ?string $treeColumn = 'parent_id';

    protected static ?string $sortColumn = 'sort';

    /**
     * Название и родитель.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required(),
            BelongsTo::make('parent_id', 'Родитель'),
        ];
    }
}
