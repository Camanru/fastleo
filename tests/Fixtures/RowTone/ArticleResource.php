<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RowTone;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Статьи с подсветкой строк по статусу: опубликованные — зелёные, на проверке — жёлтые, черновики без цвета.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'articles';

    /** Тон для проверки ошибки: если задан, возвращается для всех строк. */
    public static ?string $forced = null;

    /**
     * Название, статус и рубрика.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            Enum::make('status', 'Статус')->enum(ArticleStatus::class)->badges(),
            BelongsTo::make('category_id', 'Рубрика'),
        ];
    }

    /**
     * Цвет строки по статусу.
     */
    public function rowTone(Model $record): ?string
    {
        return self::$forced ?? match ($record->status) {
            ArticleStatus::Published => 'success',
            ArticleStatus::Review => 'warning',
            default => null,
        };
    }
}
