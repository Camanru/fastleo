<?php

declare(strict_types=1);

namespace Fastleo\Notifications;

use Fastleo\Messengers\MessengerFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Jobs\SyncJob;

/**
 * Сообщение администраторам в один чат через бота раздела «Уведомления». Одна задача — один чат:
 * повтор после сбоя не дублирует сообщение в чатах, куда оно уже ушло. С очередью `sync` выполняется сразу, а сбой
 * мессенджера записывается в лог и не ломает страницу сайта, с которой отправлено уведомление.
 *
 * Три попытки с паузой; если мессенджер просит подождать — ждёт; если связь оборвалась после отправки
 * (сообщение могло дойти), не повторяет. Бот удалён или выключен — ничего не делает.
 *
 * ```php
 * SendAdminMessage::dispatch($bot, '-100200300', 'Новая заявка');
 * ```
 */
#[Tries(3)]
#[Timeout(30)]
#[Backoff([10, 60])]
#[DeleteWhenMissingModels]
final class SendAdminMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly NotificationBot $bot, public readonly string $chatId, public readonly string $text) {}

    /**
     * Отправить текст.
     *
     * @throws MessengerFailed
     */
    public function handle(): void
    {
        if (! $this->bot->enabled) {
            return;
        }

        try {
            $this->bot->bot()->sendText($this->chatId, $this->text);
        } catch (MessengerFailed $exception) {
            if ($this->job instanceof SyncJob) {
                report($exception);

                return;
            }

            if ($exception->retryAfter !== null && $this->job !== null) {
                $this->release($exception->retryAfter);

                return;
            }

            if ($exception->uncertain && $this->job !== null) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }
}
