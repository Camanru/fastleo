<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Заметка с полиморфной связью `subject()`: от неё наследуются модели для проверок `fastleo:doctor` (таблицы создаёт тест).
 */
abstract class MorphRemark extends Model
{
    /**
     * Объект заметки.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
