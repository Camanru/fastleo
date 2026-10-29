<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Узел дерева со строковым ключом (UUID) и корзиной — таблицу создаёт сам тест (`createTreeNodesTable()`).
 *
 * @property string $id
 * @property string|null $parent_id
 * @property string $title
 * @property int $sort
 */
#[Fillable(['parent_id', 'title', 'sort'])]
final class TreeNode extends Model
{
    use HasUuids;
    use SoftDeletes;

    /**
     * Родитель узла.
     *
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
