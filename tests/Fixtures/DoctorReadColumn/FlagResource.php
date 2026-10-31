<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorReadColumn;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\DoctorArticle;

/**
 * Отметка «просмотрено» в галочке `is_published` (boolean, NOT NULL) вместо даты — так делать нельзя.
 */
final class FlagResource extends Resource
{
    protected static string $model = DoctorArticle::class;

    protected static ?string $slug = 'read-flag';

    protected static ?string $readColumn = 'is_published';

    /**
     * Только название.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
        ];
    }
}
