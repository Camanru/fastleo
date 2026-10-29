<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Foundation\Auth\User;

/**
 * Пользователь без защиты массового присваивания ($guarded = []): разрешено всё.
 */
#[Unguarded]
#[Table(name: 'users')]
final class OpenUser extends User {}
