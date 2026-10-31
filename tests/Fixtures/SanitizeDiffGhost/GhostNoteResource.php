<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\SanitizeDiffGhost;

use Fastleo\Fields\RichText;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\Ghost;

/**
 * Раздел с RichText, таблицы которого нет в базе.
 */
final class GhostNoteResource extends Resource
{
    protected static string $model = Ghost::class;

    protected static ?string $slug = 'ghost-notes';

    /**
     * Поля раздела.
     */
    public function fields(): array
    {
        return [RichText::make('body')];
    }
}
