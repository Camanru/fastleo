<?php

declare(strict_types=1);

namespace Fastleo\Notifications;

use Carbon\CarbonInterface;
use Fastleo\Fastleo;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Уведомление администратору в колокольчике.
 *
 * @property string $id
 * @property string $user_id
 * @property array{title?: string, body?: string, url?: ?string, icon?: string, level?: string} $data
 * @property CarbonInterface|null $read_at
 * @property CarbonInterface|null $created_at
 */
#[Table('fastleo_notifications')]
#[Unguarded]
#[WithoutTimestamps]
final class AdminNotification extends Model
{
    use HasUuids;

    /**
     * Сколько непрочитанных уведомлений у вошедшего администратора.
     */
    public static function unreadCount(): int
    {
        $key = Fastleo::guard()->id();

        return is_scalar($key) ? self::query()->where('user_id', (string) $key)->whereNull('read_at')->count() : 0;
    }

    /**
     * Касты атрибутов.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
