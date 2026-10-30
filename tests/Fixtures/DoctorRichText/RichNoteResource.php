<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorRichText;

use Fastleo\Fields\RichText;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\RichNote;

/**
 * Раздел с одним полем RichText — для проверки потери разметки в `fastleo:doctor`.
 */
final class RichNoteResource extends Resource
{
    protected static string $model = RichNote::class;

    protected static ?string $slug = 'rich-notes';

    public function fields(): array
    {
        return [
            RichText::make('body'),
        ];
    }
}
