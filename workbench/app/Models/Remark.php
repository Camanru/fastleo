<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Workbench\Database\Factories\RemarkFactory;

/**
 * Заметка редакции о статье или товаре (поле `MorphTo`).
 *
 * @property int $id
 * @property string $body
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 */
#[Fillable(['body', 'subject_type', 'subject_id'])]
final class Remark extends Model
{
    /** @use HasFactory<RemarkFactory> */
    use HasFactory;

    /**
     * Фабрика модели.
     */
    protected static function newFactory(): RemarkFactory
    {
        return RemarkFactory::new();
    }

    /**
     * Статья или товар, о котором заметка.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
