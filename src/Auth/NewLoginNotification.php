<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use Carbon\CarbonInterface;
use Fastleo\Fastleo;
use Fastleo\Notifications\FastleoMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Письмо администратору: в его учётную запись вошли с нового устройства.
 */
final class NewLoginNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $ip  адрес, с которого вошли
     * @param  string  $userAgent  строка браузера
     * @param  CarbonInterface  $at  время входа
     */
    public function __construct(
        private readonly string $ip,
        private readonly string $userAgent,
        private readonly CarbonInterface $at,
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
     * Текст письма: когда, откуда и что делать, если это были не вы.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(__('fastleo::auth.new_login_subject', ['site' => config()->string('app.name')]))
            ->line(__('fastleo::auth.new_login_intro'))
            ->line(__('fastleo::auth.new_login_time', ['time' => $this->at->copy()->timezone(Fastleo::timezone())->format('d.m.Y H:i')]))
            ->line(__('fastleo::auth.new_login_ip', ['ip' => FastleoMessage::plain($this->ip)]))
            // Строку браузера присылает тот, кто вошёл: без экранирования в письме появилась бы его ссылка или картинка.
            ->line(__('fastleo::auth.new_login_device', ['device' => $this->userAgent === '' ? '—' : FastleoMessage::plain(mb_strimwidth($this->userAgent, 0, 255, '…'))]))
            ->line(__('fastleo::auth.new_login_warning'))
            ->action(__('fastleo::auth.new_login_action'), Fastleo::mailUrl('fastleo.profile.show'));
    }
}
