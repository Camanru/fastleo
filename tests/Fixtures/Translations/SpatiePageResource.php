<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Translations;

use Fastleo\Fields\Slug;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\SpatiePage;

/**
 * Страницы на модели со spatie-подобными переводами.
 */
final class SpatiePageResource extends Resource
{
    protected static string $model = SpatiePage::class;

    protected static ?string $slug = 'spatie-pages';

    /**
     * Многоязычный заголовок и адрес из него.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->translatable()->required()->searchable()->sortable(),
            Slug::make('slug', 'Адрес')->from('title'),
        ];
    }
}
