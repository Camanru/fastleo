<?php

declare(strict_types=1);

namespace Fastleo\SoftNetica;

use Fastleo\Fastleo;
use Fastleo\Fields\Text;
use Fastleo\Settings;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;

/**
 * Встроенная страница настроек «SoftNetica»: токен подключения, проверка связи и состояние синхронизации.
 * Токен выдаёт администратор SoftNetica; в базе он хранится зашифрованным. Выключается ключом `fastleo.softnetica.enabled`.
 */
final class SoftNeticaSettings extends Settings
{
    protected static ?string $label = 'SoftNetica';

    protected static string $icon = 'cloud';

    protected static int $position = 95;

    protected static ?string $slug = 'softnetica';

    /**
     * Токен подключения.
     */
    #[Override]
    public function fields(): array
    {
        return [
            Text::make('token', __('fastleo::softnetica.token', [], Fastleo::LOCALE))
                ->help(__('fastleo::softnetica.token_help', [], Fastleo::LOCALE))
                ->rules(['regex:'.Connection::TOKEN_PATTERN])
                ->maxLength(51),
        ];
    }

    /**
     * Токен лежит в базе зашифрованным.
     */
    #[Override]
    public function encrypted(): array
    {
        return ['token'];
    }

    /**
     * Под формой — состояние связи, кнопки «Проверить связь» и «Отключить».
     */
    #[Override]
    public function afterForm(): string
    {
        return 'fastleo::settings.softnetica';
    }

    /**
     * Страница доступна, пока функция включена в конфиге.
     */
    #[Override]
    public function canAccess(?Authenticatable $user): bool
    {
        return Connection::enabled();
    }
}
