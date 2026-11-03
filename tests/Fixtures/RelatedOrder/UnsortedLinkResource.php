<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RelatedOrder;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\ArticleLink;

/**
 * Ссылки статьи без ручного порядка: дочерний раздел связи без `$sortColumn`.
 */
final class UnsortedLinkResource extends Resource
{
    protected static string $model = ArticleLink::class;

    protected static ?string $slug = 'unsorted-links';

    /**
     * Заголовок.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Заголовок')->required()];
    }
}
