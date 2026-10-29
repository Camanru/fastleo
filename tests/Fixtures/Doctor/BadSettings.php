<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Doctor;

use Fastleo\Fields\Password;
use Fastleo\Settings;
use Override;

/**
 * Страница настроек с полем, которое на ней не работает.
 */
final class BadSettings extends Settings
{
    protected static ?string $slug = 'bad-settings';

    /**
     * Пароль на странице настроек запрещён.
     */
    #[Override]
    public function fields(): array
    {
        return [Password::make('secret')];
    }
}
