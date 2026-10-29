<?php

declare(strict_types=1);

namespace Fastleo\Notifications;

use Fastleo\Fastleo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Письмо-копия уведомления из `Fastleo::notifyAdmins()`. Идёт через очередь сайта, а без неё (`sync`) —
 * сразу. Включается ключом `fastleo.notifications.mail`.
 */
final class MessageMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly FastleoMessage $message)
    {
        $this->locale = Fastleo::LOCALE;
    }

    /**
     * Каналы: только почта (колокольчик получает `MessageNotification`).
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Письмо по сообщению.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->message->toMail();
    }
}
