<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorBrokenList;

use Fastleo\Fields\Repeater;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Page;

/**
 * Страницы со списком в списке в списке: третий уровень вложенности не работает.
 */
final class BrokenListResource extends Resource
{
    protected static string $model = Page::class;

    protected static ?string $slug = 'broken-list';

    /**
     * Повторитель с тремя уровнями.
     */
    public function fields(): array
    {
        return [
            Repeater::make('faq')->fields([
                Repeater::make('steps')->fields([
                    Repeater::make('parts')->fields([Text::make('title')]),
                ]),
            ]),
        ];
    }
}
