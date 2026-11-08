<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Workbench\App\Enums\Priority;
use Workbench\App\Enums\TaskStatus;
use Workbench\Database\Factories\TaskFactory;

/**
 * Задача витрины «Список и действия»: статус, приоритет, поставщик, срок; `completed_at` ставит TaskObserver.
 *
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property Priority $priority
 * @property int|null $supplier_id
 * @property Carbon|null $due_on
 * @property int|null $estimate
 * @property bool $is_urgent
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 */
#[Fillable(['title', 'description', 'status', 'priority', 'supplier_id', 'due_on', 'estimate', 'is_urgent'])]
final class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * Фабрика модели.
     */
    protected static function newFactory(): TaskFactory
    {
        return TaskFactory::new();
    }

    /**
     * Касты атрибутов.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => Priority::class,
            'due_on' => 'date',
            'estimate' => 'integer',
            'is_urgent' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Поставщик, к которому относится задача.
     *
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Просроченные: срок прошёл, задача не закрыта.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeOverdue(Builder $query): Builder
    {
        return $query->whereDate('due_on', '<', today())->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled]);
    }

    /**
     * Задача просрочена.
     */
    public function isOverdue(): bool
    {
        return ! $this->status->isClosed() && $this->due_on?->lt(today()) === true;
    }
}
