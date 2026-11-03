<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\TitleImage;

use Fastleo\Fields\File;
use Fastleo\Fields\Image;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи с обложкой в ячейке заголовка и вторым файлом отдельным столбцом.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    /**
     * Обложка перед заголовком, вложение — столбцом.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок'),
            Image::make('cover', 'Обложка')->inTitle()->sortable(),
            File::make('attachment', 'Вложение'),
        ];
    }
}
