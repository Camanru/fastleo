<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\SanitizeDiff;

use Fastleo\Fields\RichText;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\RichNote;

/**
 * Раздел с обычным полем, RichText и многоязычным RichText — для сравнения чистки HTML.
 */
final class MixedNoteResource extends Resource
{
    protected static string $model = RichNote::class;

    protected static ?string $slug = 'mixed-notes';

    /**
     * Поля раздела.
     */
    public function fields(): array
    {
        return [
            Text::make('title'),
            RichText::make('body'),
            RichText::make('summary')->translatable(),
        ];
    }
}
