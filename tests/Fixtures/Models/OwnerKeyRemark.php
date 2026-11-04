<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Заметка, у которой связь `morphTo()` ищет запись по своему ключу, а не по основному.
 */
#[Fillable(['body', 'subject_type', 'subject_id'])]
final class OwnerKeyRemark extends MorphRemark
{
    /**
     * Объект заметки по ключу `uuid`.
     *
     * @return MorphTo<Model, $this>
     */
    #[\Override]
    public function subject(): MorphTo
    {
        return $this->morphTo(ownerKey: 'uuid');
    }
}
