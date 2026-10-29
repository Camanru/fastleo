<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use Fastleo\Fastleo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Письмо со ссылкой для сброса пароля администратора.
 */
final class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $token  токен брокера паролей
     * @param  string  $email  адрес, для которого запрошен сброс
     * @param  int  $minutes  сколько минут ссылка действует
     */
    public function __construct(
        private readonly string $token,
        private readonly string $email,
        private readonly int $minutes,
    ) {
        $this->locale = Fastleo::LOCALE;
        $this->afterCommit();
    }

    /**
     * Канал доставки.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Текст письма со ссылкой на форму нового пароля.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(__('fastleo::auth.reset_subject', ['site' => config()->string('app.name')]))
            ->line(__('fastleo::auth.reset_intro'))
            ->action(__('fastleo::auth.reset_action'), Fastleo::mailUrl('fastleo.password.reset', ['token' => $this->token, 'email' => $this->email]))
            ->line(__('fastleo::auth.reset_expire', ['minutes' => $this->minutes]))
            ->line(__('fastleo::auth.reset_ignore'));
    }
}
