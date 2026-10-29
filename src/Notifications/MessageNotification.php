<?php

declare(strict_types=1);

namespace Fastleo\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Уведомление Laravel для канала `fastleo` (колокольчик): оборачивает `FastleoMessage`.
 * Свои классы уведомлений тоже могут слать в колокольчик: `via()` возвращает `['fastleo']`,
 * а метод `toFastleo($notifiable)` — `FastleoMessage`.
 */
final class MessageNotification extends Notification
{
    public function __construct(private readonly FastleoMessage $message) {}

    /**
     * Канал доставки.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['fastleo'];
    }

    /**
     * Сообщение для колокольчика.
     */
    public function toFastleo(object $notifiable): FastleoMessage
    {
        return $this->message;
    }
}
