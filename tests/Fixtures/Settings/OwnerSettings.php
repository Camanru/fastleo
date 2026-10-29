<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Settings;

use Fastleo\Fields\Text;
use Fastleo\Settings;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Настройки только для администратора owner@example.com.
 */
final class OwnerSettings extends Settings
{
    /**
     * Одно поле.
     */
    #[Override]
    public function fields(): array
    {
        return [Text::make('note', 'Заметка')];
    }

    /**
     * Доступ по email.
     */
    #[Override]
    public function canAccess(?Authenticatable $user): bool
    {
        return $user instanceof Model && $user->getAttribute('email') === 'owner@example.com';
    }
}
