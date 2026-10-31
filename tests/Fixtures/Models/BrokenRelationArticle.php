<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Workbench\App\Models\ArticleLink;

/**
 * Модель таблицы articles со связями на модель без таблицы (`Ghost`) — для проверок `fastleo:doctor`.
 */
#[Fillable(['title', 'category_id'])]
#[Table(name: 'articles')]
final class BrokenRelationArticle extends Model
{
    /**
     * Связь на модель, чьей таблицы нет в базе.
     *
     * @return BelongsTo<Ghost, $this>
     */
    public function ghost(): BelongsTo
    {
        return $this->belongsTo(Ghost::class, 'category_id');
    }

    /**
     * Связь «многие ко многим» на модель без таблицы.
     *
     * @return BelongsToMany<Ghost, $this>
     */
    public function ghosts(): BelongsToMany
    {
        return $this->belongsToMany(Ghost::class, 'article_ghost');
    }

    /**
     * Блок «один ко многим» на модель без таблицы.
     *
     * @return HasMany<Ghost, $this>
     */
    public function ghostLinks(): HasMany
    {
        return $this->hasMany(Ghost::class, 'article_id');
    }

    /**
     * Блок с настоящей таблицей: его поля описаны неверно.
     *
     * @return HasMany<ArticleLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(ArticleLink::class, 'article_id');
    }
}
