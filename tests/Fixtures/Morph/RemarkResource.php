<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Morph;

use Fastleo\Fields\MorphTo;
use Fastleo\Fields\Select;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use PDOException;
use Workbench\App\Models\Remark;

/**
 * Заметки о статье или товаре; тест выбирает вариант поля: `default`, `required`, `articles` (только статьи), `self` (заметки о заметках), `broken` (запрос статей падает), `readonly`, `format`, `selfFirst` (объект первым столбцом, заметки о заметках), `controlling` (текст показывается по объекту), `depending` (список зависит от объекта), `inline` (текст правится в ячейке).
 */
final class RemarkResource extends Resource
{
    protected static string $model = Remark::class;

    protected static ?string $slug = 'remarks';

    protected static bool $importable = true;

    /** Вариант поля. */
    public static string $variant = 'default';

    /**
     * Текст заметки и объект.
     */
    public function fields(): array
    {
        $subject = MorphTo::make('subject', 'О чём')->types(match (self::$variant) {
            'articles' => [ArticleResource::class],
            'self', 'selfFirst' => [self::class],
            'broken' => [ArticleResource::class => fn (): never => throw new QueryException('sqlite', 'select 1', [], new PDOException('База недоступна'))],
            default => [ArticleResource::class, ProductResource::class => fn (Builder $query) => $query->where('is_visible', true)],
        });

        if (self::$variant === 'selfFirst') {
            return [$subject, Text::make('body', 'Заметка')];
        }

        return [
            match (self::$variant) {
                'controlling' => Text::make('body', 'Заметка')->visibleWhen('subject', 'x'),
                'depending' => Select::make('body', 'Заметка')->options(fn (): array => [])->dependsOn('subject'),
                'inline' => Text::make('body', 'Заметка')->required()->inlineEditable(),
                default => Text::make('body', 'Заметка')->required(),
            },
            match (self::$variant) {
                'required' => $subject->required(),
                'readonly' => $subject->readonly(),
                'format' => $subject->formatUsing(fn (mixed $value): string => 'формат:'.json_encode($value)),
                default => $subject,
            },
        ];
    }
}
