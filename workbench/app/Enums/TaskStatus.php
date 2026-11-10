<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

/**
 * Статус задачи витрины: строковый enum с подписями и цветными метками.
 */
enum TaskStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';

    /**
     * Подпись для панели.
     */
    public function label(): string
    {
        return match ($this) {
            self::New => 'Новая',
            self::InProgress => 'В работе',
            self::Done => 'Выполнена',
            self::Cancelled => 'Отменена',
        };
    }

    /**
     * Тон цветной метки в списке (`Enum::badges()`).
     */
    public function tone(): string
    {
        return match ($this) {
            self::New => 'info',
            self::InProgress => 'warning',
            self::Done => 'success',
            self::Cancelled => 'neutral',
        };
    }

    /**
     * Задача закрыта: выполнена или отменена.
     */
    public function isClosed(): bool
    {
        return $this === self::Done || $this === self::Cancelled;
    }
}
