<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель с узкими колонками (таблицу `doctor_columns` создаёт тест) — на соединении `testing`,
 * чтобы проверять колонки так, будто основное соединение сайта — другая база.
 */
#[Table('doctor_columns')]
#[Fillable(['note', 'lowest', 'kind', 'tiny', 'tiny_unsigned', 'mode', 'stamp'])]
#[WithoutTimestamps]
#[Connection('testing')]
final class ColumnThing extends Model {}
