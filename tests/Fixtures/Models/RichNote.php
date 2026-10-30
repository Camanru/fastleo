<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель с текстом из визуального редактора — таблицу создаёт сам тест.
 */
#[Table('doctor_rich_notes')]
#[Fillable(['body'])]
#[WithoutTimestamps]
final class RichNote extends Model {}
