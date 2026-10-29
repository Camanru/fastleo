<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\SettingsDuplicate;

use Fastleo\Fields\Text;
use Fastleo\Settings;

/**
 * Первая страница настроек с адресом «same».
 */
final class FirstSettings extends Settings
{
    protected static ?string $slug = 'same';

    /**
     * Поле.
     */
    public function fields(): array
    {
        return [Text::make('a')];
    }
}
