<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Workbench\App\Enums\ArticleStatus;
use Workbench\Database\Factories\ArticleFactory;

/**
 * Статья демо-сайта: все базовые типы полей, корзина (SoftDeletes).
 *
 * @property int $id
 * @property string $type
 * @property string $title
 * @property string|null $excerpt
 * @property ArticleStatus $status
 * @property list<string>|null $tags
 * @property int $views
 * @property string|null $price
 * @property bool $is_featured
 * @property bool $is_published
 * @property Carbon|null $published_on
 * @property Carbon|null $starts_at
 * @property string|null $opens_at
 * @property string|null $author_email
 * @property string|null $source_url
 * @property string|null $content
 * @property string|null $cover
 * @property list<string>|null $gallery
 * @property array{title?: string, description?: string, image?: string, noindex?: bool}|null $seo
 * @property string|null $attachment
 * @property string|null $phone
 * @property string|null $color
 */
#[Fillable([
    'type', 'category_id', 'subcategory_id', 'title', 'excerpt', 'status', 'tags', 'views', 'price', 'is_featured', 'is_published',
    'published_on', 'starts_at', 'opens_at', 'author_email', 'source_url', 'phone', 'color',
    'slug', 'layout', 'rating', 'keywords', 'body', 'head_code', 'content', 'cover', 'gallery', 'attachment', 'seo',
])]
final class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Фабрика модели.
     */
    protected static function newFactory(): ArticleFactory
    {
        return ArticleFactory::new();
    }

    /**
     * Касты атрибутов.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => ArticleStatus::class,
            'tags' => 'array',
            'keywords' => 'array',
            'gallery' => 'array',
            'seo' => 'array',
            'rating' => 'integer',
            'views' => 'integer',
            'price' => 'decimal:2',
            'is_featured' => 'boolean',
            'is_published' => 'boolean',
            'published_on' => 'date',
            'starts_at' => 'datetime',
        ];
    }

    /**
     * Категория статьи.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Подраздел статьи (вложенная категория выбранной категории).
     *
     * @return BelongsTo<Category, $this>
     */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }

    /**
     * Темы статьи.
     *
     * @return BelongsToMany<Topic, $this>
     */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class);
    }

    /**
     * Полезные ссылки статьи.
     *
     * @return HasMany<ArticleLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(ArticleLink::class);
    }

    /**
     * Баннер статьи.
     *
     * @return HasOne<ArticleBanner, $this>
     */
    public function banner(): HasOne
    {
        return $this->hasOne(ArticleBanner::class);
    }
}
