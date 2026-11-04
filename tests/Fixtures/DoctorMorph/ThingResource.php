<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorMorph;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\UuidThing;

/**
 * Вещи со строковым ключом — раздел-тип.
 */
final class ThingResource extends Resource
{
    protected static string $model = UuidThing::class;

    protected static ?string $slug = 'things';

    /**
     * Название.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Название')];
    }
}
