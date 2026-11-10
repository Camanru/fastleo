<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Registry\UsersSubclassSecond;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\User;

/**
 * Свой раздел с адресом «users», не наследник «Пользователей»: он идёт первым по имени файла.
 */
final class ArchiveUsersResource extends Resource
{
    protected static string $model = User::class;

    protected static ?string $slug = 'users';

    /**
     * Название.
     */
    public function fields(): array
    {
        return [Text::make('name')];
    }
}
