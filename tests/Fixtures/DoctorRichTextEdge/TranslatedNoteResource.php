<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorRichTextEdge;

use Fastleo\Fields\RichText;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\RichNote;

/**
 * Раздел с многоязычным RichText: в колонке JSON с текстом по языкам.
 */
final class TranslatedNoteResource extends Resource
{
    protected static string $model = RichNote::class;

    protected static ?string $slug = 'translated-notes';

    public function fields(): array
    {
        return [
            RichText::make('body')->translatable(),
        ];
    }
}
