<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Fastleo\Auth\FastleoUser;
use Fastleo\Auth\HasFastleoAccess;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * Пользователь сайта, который умеет входить, но не умеет сбрасывать пароль (нет `CanResetPassword`).
 */
#[Unguarded]
#[Table(name: 'users')]
final class NoResetUser extends Model implements AuthenticatableContract, FastleoUser
{
    use Authenticatable;
    use HasFastleoAccess;
}
