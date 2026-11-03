<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorEnumText;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Раздел только для чтения: форма ничего не пишет, поэтому текст над enum-колонкой не ломает запись.
 */
final class StatusInReadonlySectionResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'status-readonly-section';

    protected static bool $readonly = true;

    /**
     * Название и статус текстом.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            Text::make('status'),
        ];
    }
}
