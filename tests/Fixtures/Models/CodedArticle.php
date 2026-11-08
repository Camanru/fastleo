<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workbench\App\Models\Category;

/**
 * Модель таблицы articles со связью с категорией по её коду, а не по первичному ключу — для проверок `fastleo:doctor`.
 */
#[Fillable(['title', 'category_id'])]
#[Table(name: 'articles')]
final class CodedArticle extends Model
{
    /**
     * Категория по колонке `code`.
     *
     * @return BelongsTo<Category, $this>
     */
    public function categoryByCode(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'code');
    }
}
