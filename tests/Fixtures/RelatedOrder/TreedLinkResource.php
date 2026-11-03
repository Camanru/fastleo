<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RelatedOrder;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\ArticleLink;

/**
 * Ссылки статьи с деревом и порядком: порядок в связанной таблице с деревом не меняется. Колонка дерева
 * здесь только задаёт вид раздела — тест проверяет отказ по её наличию, ветки в нём не строятся.
 */
final class TreedLinkResource extends Resource
{
    protected static string $model = ArticleLink::class;

    protected static ?string $slug = 'treed-links';

    protected static ?string $sortColumn = 'sort';

    protected static ?string $treeColumn = 'article_id';

    /**
     * Заголовок.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Заголовок')->required()];
    }
}
