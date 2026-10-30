<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorRichText;

use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Fields\StrictRichText;
use Fastleo\Tests\Fixtures\Models\RichNote;

/**
 * Раздел с полем RichText со своим `sanitize()` — подсказка `fastleo:doctor` называет этот метод.
 */
final class StrictRichNoteResource extends Resource
{
    protected static string $model = RichNote::class;

    protected static ?string $slug = 'strict-rich-notes';

    public function fields(): array
    {
        return [
            StrictRichText::make('body'),
        ];
    }
}
