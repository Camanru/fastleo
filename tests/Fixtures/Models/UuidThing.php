<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель со строковым ключом: разделы с числовым и строковым ключом в одном поле MorphTo не помещаются в одну колонку.
 */
#[Fillable(['title'])]
#[WithoutIncrementing]
final class UuidThing extends Model
{
    protected $keyType = 'string';
}
