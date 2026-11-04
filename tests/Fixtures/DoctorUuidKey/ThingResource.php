<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorUuidKey;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\PlainUuidThing;

/**
 * Раздел с ключом-uuid у модели без `HasUuids`.
 */
final class ThingResource extends Resource
{
    protected static string $model = PlainUuidThing::class;

    protected static ?string $slug = 'plain-uuid-things';

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
