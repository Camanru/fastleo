<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\SanitizeDiff;

use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\RichNote;
use LogicException;

/**
 * Раздел, описание которого падает: сравнение его пропускает, ошибку показывает doctor.
 */
final class BrokenSchemaResource extends Resource
{
    protected static string $model = RichNote::class;

    protected static ?string $slug = 'broken-schema';

    /**
     * Поля раздела.
     */
    public function fields(): array
    {
        throw new LogicException('Описание раздела сломано.');
    }
}
