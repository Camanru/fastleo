<?php

declare(strict_types=1);

namespace Fastleo\Closing;

use Fastleo\Fastleo;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Toggle;
use Fastleo\Settings;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;

/**
 * Встроенная страница настроек «Закрытие сайта»: галочка и текст для посетителей. Появляется в меню,
 * когда включён ключ `fastleo.closing.enabled`.
 */
final class ClosingSettings extends Settings
{
    protected static ?string $label = 'Закрытие сайта';

    protected static string $icon = 'power';

    protected static int $position = 90;

    protected static ?string $slug = 'closing';

    /**
     * Галочка закрытия и текст для посетителей.
     */
    #[Override]
    public function fields(): array
    {
        return [
            Toggle::make('closed', __('fastleo::closing.closed', [], Fastleo::LOCALE))
                ->help(__('fastleo::closing.closed_help', [], Fastleo::LOCALE)),
            Textarea::make('message', __('fastleo::closing.message', [], Fastleo::LOCALE))
                ->help(__('fastleo::closing.message_help', [], Fastleo::LOCALE))
                ->maxLength(500),
        ];
    }

    /**
     * Страница доступна, пока функция включена в конфиге.
     */
    #[Override]
    public function canAccess(?Authenticatable $user): bool
    {
        return SiteClosing::enabled();
    }
}
