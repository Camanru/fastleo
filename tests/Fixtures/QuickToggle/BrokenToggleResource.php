<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\QuickToggle;

use Fastleo\Actions\SetToggle;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Действие «Показать» направлено на поле, которое не галочка.
 */
final class BrokenToggleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'broken-toggle';

    /**
     * Только заголовок.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Заголовок')->required()];
    }

    /**
     * Действие на поле `title`.
     */
    public function actions(): array
    {
        return [SetToggle::make('title', 'Показать', true)];
    }
}
