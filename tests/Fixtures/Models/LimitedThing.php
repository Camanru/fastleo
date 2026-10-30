<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель с колонками ограниченной длины и диапазона — таблицу создаёт сам тест (`createLimitsTable()`).
 */
#[Table('doctor_limits')]
#[Fillable(['code', 'note', 'amount', 'small', 'slug', 'kind', 'title', 'phone'])]
#[WithoutTimestamps]
final class LimitedThing extends Model {}
