<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\HideEmpty;

use Fastleo\Fields\Email;
use Fastleo\Fields\Image;
use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи со столбцами, которые исчезают, пока пусты.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    /**
     * Название (тоже с `hideWhenEmpty()`, но первый столбец остаётся), телефон, почта и обложка.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->hideWhenEmpty(),
            Tel::make('phone', 'Телефон')->hideWhenEmpty(),
            Email::make('author_email', 'Почта автора')->sortable()->hideWhenEmpty(),
            Image::make('cover', 'Обложка')->hideWhenEmpty(),
        ];
    }
}
