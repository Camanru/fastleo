<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\SanitizeDiff;

use Fastleo\Fields\RichText;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\RichNote;

/**
 * Раздел только для просмотра с RichText: чистка HTML его не трогает.
 */
final class ReadonlyNoteResource extends Resource
{
    protected static string $model = RichNote::class;

    protected static ?string $slug = 'readonly-notes';

    protected static bool $readonly = true;

    /**
     * Поля раздела.
     */
    public function fields(): array
    {
        return [RichText::make('body')];
    }
}
