<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorRichTextEdge;

use Fastleo\Fields\RichText;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\MutatedRichNote;

/**
 * Раздел с RichText, у которого нет колонки: текст пишет мутатор модели, читать записи для проверки нечем.
 */
final class MutatedNoteResource extends Resource
{
    protected static string $model = MutatedRichNote::class;

    protected static ?string $slug = 'mutated-notes';

    public function fields(): array
    {
        return [
            RichText::make('html'),
        ];
    }
}
