<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Users;

use Fastleo\Fields\Email;
use Fastleo\Fields\Password;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\User;

/**
 * Пользователи с полем пароля — для тестов Password.
 */
final class UserResource extends Resource
{
    protected static string $model = User::class;

    /**
     * Имя, email, пароль с повтором.
     */
    public function fields(): array
    {
        return [
            Text::make('name')->required(),
            Email::make('email')->required(),
            Password::make('password', 'Пароль')->required()->confirmed(),
        ];
    }
}
