<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

/**
 * Статус статьи демо-сайта.
 */
enum ArticleStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Published = 'published';

    /**
     * Подпись для админки.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Review => 'На проверке',
            self::Published => 'Опубликовано',
        };
    }

    /**
     * Тон цветной метки в списке (`Enum::badges()`).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Review => 'warning',
            self::Published => 'success',
        };
    }
}
