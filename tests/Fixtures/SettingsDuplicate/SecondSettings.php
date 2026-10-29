<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\SettingsDuplicate;

use Fastleo\Fields\Text;
use Fastleo\Settings;

/**
 * Вторая страница настроек с тем же адресом.
 */
final class SecondSettings extends Settings
{
    protected static ?string $slug = 'same';

    /**
     * Поле.
     */
    public function fields(): array
    {
        return [Text::make('b')];
    }
}
