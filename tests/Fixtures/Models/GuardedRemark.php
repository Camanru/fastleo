<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * Заметка с колонками `subject_type` и `subject_id`, которые не внесены в `$fillable`.
 */
#[Fillable(['body'])]
final class GuardedRemark extends MorphRemark {}
