<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Secrets;

use Fastleo\Fields\Password;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\SecretNote;

/**
 * Заметки с полем пароля под нестандартным именем `pin`: оно не должно попадать в сессию и журнал изменений.
 */
final class SecretNoteResource extends Resource
{
    protected static string $model = SecretNote::class;

    /**
     * Название и секретный пин-код.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required(),
            Password::make('pin', 'Пин-код'),
        ];
    }
}
