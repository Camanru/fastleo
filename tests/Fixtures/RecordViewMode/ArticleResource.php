<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RecordViewMode;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Article;

/**
 * Статьи со своим режимом просмотра записи: что задать — решает тест (`$recordView`, запрет правки, поля только для чтения).
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'articles';

    public static string|false|null $recordView = null;

    /** Все поля только для чтения: запись читают, но не правят. */
    public static bool $readOnlyFields = false;

    /** Администратору разрешено менять записи. */
    public static bool $canUpdate = true;

    /**
     * Заголовок; у «читающего» режима — без права записи.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Заголовок')->required()->readonly(self::$readOnlyFields)];
    }

    /**
     * Менять записи можно, пока тест не запретил.
     */
    public function can(string $ability, ?Model $record): bool
    {
        return $ability !== 'update' || self::$canUpdate;
    }
}
