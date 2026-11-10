<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

/**
 * Приоритет витрины: целочисленный enum (колонка `unsignedTinyInteger`) с подписями и цветными метками.
 */
enum Priority: int
{
    case Low = 1;
    case Normal = 2;
    case High = 3;

    /**
     * Подпись для панели.
     */
    public function label(): string
    {
        return match ($this) {
            self::Low => 'Низкий',
            self::Normal => 'Обычный',
            self::High => 'Высокий',
        };
    }

    /**
     * Тон цветной метки в списке (`Enum::badges()`).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Low => 'neutral',
            self::Normal => 'info',
            self::High => 'danger',
        };
    }
}
