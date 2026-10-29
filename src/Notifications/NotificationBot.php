<?php

declare(strict_types=1);

namespace Fastleo\Notifications;

use Fastleo\Messengers\Bot;
use Fastleo\Messengers\Messenger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Бот, который дублирует уведомления администраторам в Telegram или Max (раздел «Уведомления»).
 * Токен хранится зашифрованным и скрыт при выводе модели.
 *
 * @property int $id
 * @property string $name
 * @property Messenger $messenger
 * @property string $token
 * @property list<string> $recipients
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'messenger', 'token', 'recipients', 'enabled'])]
#[Hidden(['token'])]
#[Table(name: 'fastleo_notification_bots')]
final class NotificationBot extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['enabled' => true];

    /**
     * Клиент бота.
     */
    public function bot(): Bot
    {
        return $this->messenger->bot($this->token);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'messenger' => Messenger::class,
            'token' => 'encrypted',
            'recipients' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
