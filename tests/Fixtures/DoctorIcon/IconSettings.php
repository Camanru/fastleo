<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorIcon;

use Fastleo\Fields\Text;
use Fastleo\Settings;

/**
 * Настройки с иконкой, которой нет в наборе Lucide.
 */
final class IconSettings extends Settings
{
    protected static ?string $slug = 'bad-settings-icon';

    protected static string $icon = 'message-square-star';

    /**
     * Одно поле.
     */
    public function fields(): array
    {
        return [Text::make('note', 'Заметка')];
    }
}
