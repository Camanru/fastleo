<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Полезная ссылка статьи (вложенные блоки HasMany).
 *
 * @property int $id
 * @property int $article_id
 * @property string $title
 * @property string|null $url
 * @property int $sort
 */
#[Fillable(['title', 'url'])]
final class ArticleLink extends Model
{
    /**
     * Статья ссылки.
     *
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
