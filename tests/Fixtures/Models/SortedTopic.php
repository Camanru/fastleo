<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Тема, у которой колонка порядка входит в `$fillable` — её можно менять из формы.
 *
 * @property int $id
 * @property string $title
 * @property int $sort
 */
#[Table('topics')]
#[Fillable(['title', 'sort'])]
final class SortedTopic extends Model {}
