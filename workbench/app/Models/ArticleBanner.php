<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Баннер статьи (одна вложенная запись HasOne).
 *
 * @property int $id
 * @property int $article_id
 * @property string|null $title
 * @property string|null $link
 * @property string|null $image
 * @property bool $is_active
 */
#[Fillable(['title', 'link', 'image', 'is_active'])]
final class ArticleBanner extends Model
{
    /**
     * Приведение типов.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
