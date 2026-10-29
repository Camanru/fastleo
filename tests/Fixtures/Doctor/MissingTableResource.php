<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Doctor;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\Ghost;

/**
 * Раздел модели, таблицы которой нет в базе.
 */
final class MissingTableResource extends Resource
{
    protected static string $model = Ghost::class;

    protected static ?string $slug = 'missing-table';

    /**
     * Одно поле.
     */
    public function fields(): array
    {
        return [Text::make('name')];
    }
}
