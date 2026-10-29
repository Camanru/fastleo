<?php

declare(strict_types=1);

namespace Fastleo\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Канал Laravel Notifications `fastleo`: кладёт уведомление в колокольчик администратора.
 * Уведомление должно иметь метод `toFastleo($notifiable): FastleoMessage`.
 */
final class FastleoChannel
{
    /**
     * Сохранить уведомление пользователю. Адресат без ключа или уведомление без `toFastleo()` пропускается.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        $key = method_exists($notifiable, 'getAuthIdentifier') ? $notifiable->getAuthIdentifier() : null;

        if (! is_scalar($key) || ! method_exists($notification, 'toFastleo')) {
            return;
        }

        $message = $notification->toFastleo($notifiable);

        if (! $message instanceof FastleoMessage) {
            return;
        }

        AdminNotification::query()->create(['user_id' => (string) $key, 'data' => $message->toArray(), 'created_at' => now()]);
    }
}
