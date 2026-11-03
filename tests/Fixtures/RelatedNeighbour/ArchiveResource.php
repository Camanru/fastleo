<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RelatedNeighbour;

use Fastleo\Fields\Text;
use Fastleo\Related;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Архив рубрик с ошибкой описания: связь «articles» указана дважды. Файл назван так, чтобы поиск связи действия
 * дошёл до него раньше рубрик.
 */
final class ArchiveResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'archive';

    /**
     * Название.
     */
    public function fields(): array
    {
        return [Text::make('title')];
    }

    /**
     * Связь описана дважды — listRelations() бросит ошибку описания.
     */
    public function relations(): array
    {
        return [Related::make('articles', ArticleResource::class), Related::make('articles', ArticleResource::class)];
    }
}
