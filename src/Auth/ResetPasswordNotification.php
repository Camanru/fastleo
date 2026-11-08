<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use Fastleo\Fastleo;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Письмо со ссылкой для сброса пароля администратора.
 *
 * Не `ShouldQueue`: токен ссылки лежал бы в таблице `jobs` (и в `failed_jobs`) открытым текстом. Контроллер отправляет письмо
 * после ответа (`defer()`), так что запрос оно не задерживает и без очереди.
 */
final class ResetPasswordNotification extends Notification
{
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
