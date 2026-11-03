<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorEnumText;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статус статьи (колонка с enum-кастом) описан текстовым полем — так делать нельзя.
 */
final class StatusTextResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'status-text';

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
