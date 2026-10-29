<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Устройство, с которого администратор входил в админку: пара «IP + браузер».
 *
 * @property int $id
 * @property string $user_id
 * @property string $fingerprint
 * @property string $ip
 * @property string|null $user_agent
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $last_seen_at
 */
#[Table('fastleo_logins')]
#[Unguarded]
#[WithoutTimestamps]
final class LoginRecord extends Model
{
    /**
     * Касты атрибутов.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
