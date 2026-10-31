<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\MakeResource;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Модель с методом `owner()`, который падает при вызове, — таблицу создаёт сам тест.
 */
#[Table('broken_relation_things')]
#[Fillable(['title', 'owner_id'])]
#[WithoutTimestamps]
final class BrokenRelationThing extends Model
{
    /**
     * Метод, похожий на связь, но неработающий.
     */
    public function owner(): never
    {
        throw new RuntimeException('Связь сломана');
    }
}
