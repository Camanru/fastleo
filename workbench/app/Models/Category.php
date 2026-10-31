<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Workbench\Database\Factories\CategoryFactory;

/**
 * Категория демо-сайта. Колонка internal_code не в $fillable — её нельзя менять из формы.
 *
 * @property int $id
 * @property string $title
 * @property string|null $code
 * @property string|null $internal_code
 */
#[Fillable(['parent_id', 'title', 'code'])]
final class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * Фабрика модели.
     */
    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }

    /**
     * Статьи категории.
     *
     * @return HasMany<Article, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    /**
     * Опубликованные статьи категории: связь со своим условием (для связанных таблиц админки).
     *
     * @return HasMany<Article, $this>
     */
    public function publishedArticles(): HasMany
    {
        return $this->hasMany(Article::class)->where('status', 'published');
    }

    /**
     * Готовые статьи категории: связь с `orWhere` (на ревью или опубликованные).
     *
     * @return HasMany<Article, $this>
     */
    public function readyArticles(): HasMany
    {
        return $this->hasMany(Article::class)->where('status', 'review')->orWhere('status', 'published');
    }

    /**
     * Родительская категория (дерево).
     *
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Вложенные категории.
     *
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
