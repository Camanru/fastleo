<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Translations;

use Fastleo\Fields\Slug;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Page;

/**
 * Страницы с многоязычным заголовком в колонке с кастом array; можно загружать из файла.
 */
final class PageResource extends Resource
{
    protected static string $model = Page::class;

    protected static ?string $slug = 'site-pages';

    protected static bool $importable = true;

    /**
     * Заголовок и адрес.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->translatable()->required()->searchable()->sortable()->maxLength(20),
            Slug::make('slug', 'Адрес')->from('title'),
        ];
    }
}
