<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Workbench\App\Models\Category;
use Workbench\App\Models\User;

/**
 * Модель со связями разного вида — для проверок описания полей-связей (без таблицы).
 */
final class Assignment extends Model
{
    /**
     * Связанная модель с колонкой `name`.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Связанная модель без `title` и `name` в `$fillable`.
     *
     * @return BelongsTo<Ghost, $this>
     */
    public function ghost(): BelongsTo
    {
        return $this->belongsTo(Ghost::class);
    }

    /**
     * Связь с нестандартным внешним ключом.
     *
     * @return BelongsTo<Category, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_ref');
    }

    /**
     * Связь по нестандартному ключу связанной таблицы (не по первичному).
     *
     * @return BelongsTo<Category, $this>
     */
    public function byCode(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_code', 'code');
    }

    /**
     * Связь «многие ко многим» по нестандартному ключу связанной таблицы.
     *
     * @return BelongsToMany<Category, $this>
     */
    public function codes(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'assignment_category', 'assignment_id', 'category_code', null, 'code');
    }

    /**
     * Метод, который не возвращает связь.
     */
    public function label(): string
    {
        return 'не связь';
    }
}
