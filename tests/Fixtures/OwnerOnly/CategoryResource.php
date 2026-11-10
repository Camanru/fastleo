<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\OwnerOnly;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Рубрики, закрытые для всех, кроме владельца: подписи их записей не должны утекать через поля других разделов.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'categories';

    /** @var list<string> */
    public static array $ownerOnly = ['*'];

    /**
     * Название.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Название')->required()];
    }
}
