<?php

declare(strict_types=1);

namespace Fastleo\Leads;

use Fastleo\Fastleo;

/**
 * Статус заявки: подпись и цвет метки в панели.
 */
enum LeadStatus: string
{
    case New = 'new';
    case InWork = 'in_work';
    case Done = 'done';
    case Spam = 'spam';

    /**
     * Подпись в панели.
     */
    public function label(): string
    {
        return __('fastleo::leads.status_'.$this->value, [], Fastleo::LOCALE);
    }

    /**
     * Цвет метки: success, warning, danger, info или neutral.
     */
    public function tone(): string
    {
        return match ($this) {
            self::New => 'info',
            self::InWork => 'warning',
            self::Done => 'success',
            self::Spam => 'neutral',
        };
    }
}
