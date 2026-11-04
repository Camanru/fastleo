<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель с ключом-uuid, но без трейта `HasUuids` — для проверки `fastleo:doctor`.
 */
#[Fillable(['title'])]
#[Table(name: 'plain_uuid_things', keyType: 'string', incrementing: false)]
final class PlainUuidThing extends Model {}
