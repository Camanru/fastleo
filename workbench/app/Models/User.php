<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Fastleo\Auth\FastleoUser;
use Fastleo\Auth\HasFastleoAccess;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Workbench\Database\Factories\UserFactory;

/**
 * Пользователь демо-сайта: обычная модель Laravel + доступ в админку по колонке fastleo_admin.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $fastleo_admin
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
final class User extends Authenticatable implements FastleoUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasFastleoAccess;
    use Notifiable;

    /**
     * Фабрика модели.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * Касты атрибутов.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'fastleo_admin' => 'boolean',
        ];
    }
}
