<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorEnumText;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статус только для чтения: из формы он не пишется, поэтому текст над enum-колонкой не ломает запись.
 */
final class StatusReadonlyResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'status-readonly';

    /**
     * Название и статус текстом только для показа.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            Text::make('status')->readonly(),
        ];
    }
}
