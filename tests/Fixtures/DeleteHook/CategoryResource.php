<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DeleteHook;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Support\OperationCancelled;
use Illuminate\Database\Eloquent\Model;
use Override;
use Workbench\App\Models\Category;

/**
 * Категории, которые нельзя удалять, пока у них код `keep`.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    /**
     * Название и код.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required(),
            Text::make('code', 'Код')->maxLength(50),
        ];
    }

    /**
     * Категорию с кодом `keep` удалить нельзя.
     */
    #[Override]
    public function beforeDelete(Model $record): void
    {
        if ($record->getAttribute('code') === 'keep') {
            throw new OperationCancelled('Категория нужна сайту.');
        }
    }
}
