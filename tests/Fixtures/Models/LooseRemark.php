<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * Заметка, в таблице которой нет колонок `subject_type` и `subject_id`.
 */
#[Fillable(['body'])]
final class LooseRemark extends MorphRemark {}
