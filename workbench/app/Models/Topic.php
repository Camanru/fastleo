<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Workbench\Database\Factories\TopicFactory;

/**
 * Тема статей демо-сайта (связь многие ко многим).
 *
 * @property int $id
 * @property string $title
 */
#[Fillable(['title'])]
final class Topic extends Model
{
    /** @use HasFactory<TopicFactory> */
    use HasFactory;

    /**
     * Фабрика тем.
     */
    protected static function newFactory(): TopicFactory
    {
        return TopicFactory::new();
    }

    /**
     * Статьи темы.
     *
     * @return BelongsToMany<Article, $this>
     */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class);
    }
}
