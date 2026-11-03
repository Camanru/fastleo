<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RelatedOrder;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\ArticleLink;

/**
 * Ссылки статьи с ручным порядком, но раздел только для просмотра: перенос запрещён.
 */
final class ReadonlyLinkResource extends Resource
{
    protected static string $model = ArticleLink::class;

    protected static ?string $slug = 'readonly-links';

    protected static ?string $sortColumn = 'sort';

    protected static bool $readonly = true;

    /**
     * Заголовок.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Заголовок')->required()];
    }
}
