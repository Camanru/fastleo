<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель с колонками, у которых есть DEFAULT, и значением в `$attributes` — таблицу создаёт сам тест (`createDefaultedThingsTable()`).
 */
#[Table('defaulted_things')]
#[Fillable(['is_active', 'is_hidden', 'title', 'sort', 'kind', 'note', 'published_at', 'mood', 'legacy_at'])]
#[WithoutTimestamps]
final class DefaultedThing extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['kind' => 'basic'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_hidden' => 'boolean', 'mood' => DefaultedMood::class];
    }
}
