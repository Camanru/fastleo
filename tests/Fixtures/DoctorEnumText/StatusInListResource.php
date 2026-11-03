<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorEnumText;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статус только в списке: в форме его нет, из формы он не пишется.
 */
final class StatusInListResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'status-in-list';

    /**
     * Название и статус только в списке.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            Text::make('status')->onlyInList(),
        ];
    }
}
