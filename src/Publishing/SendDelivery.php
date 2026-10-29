<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Fastleo\Fastleo;
use Fastleo\Messengers\MessengerFailed;
use Fastleo\Notifications\FastleoMessage;
use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\NullQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Queue as QueueFacade;
use Throwable;

/**
 * Отправка публикации в один канал. Одна задача — одна доставка; с очередью `sync` выполняется сразу.
 *
 * Доставка забирается атомарно (`pending` → `sending`), поэтому две задачи не отправят пост дважды. Номера
 * сообщений сохраняются после каждого шага: повтор продолжает с места сбоя. Если связь оборвалась после
 * отправки (пост мог дойти), доставка получает статус `unknown` и сама не повторяется. Мессенджер просит
 * подождать — задача откладывается. Ошибки записываются в доставку и не выбрасываются: отправка из админки
 * без очереди не ломает страницу. Если что-то не дошло, автор получает уведомление в колокольчик.
 *
 * ```php
 * SendDelivery::dispatch($delivery);
 * ```
 */
#[Tries(5)]
#[Timeout(300)]
#[DeleteWhenMissingModels]
final class SendDelivery implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Delivery $delivery) {}

    /**
     * Очередь, в которую попадут задачи отправки (с учётом `Queue::route()` сайта).
     */
    public static function targetQueue(): Queue
    {
        return QueueFacade::connection(resolve(Dispatcher::class)->resolveConnectionFromQueueRoute(new self(new Delivery)));
    }

    /**
     * Очередь умеет откладывать задачи: не `sync` (и не её варианты `deferred`, `background`) и не `null`.
     */
    public static function canDelay(): bool
    {
        $queue = self::targetQueue();

        return ! $queue instanceof SyncQueue && ! $queue instanceof NullQueue;
    }

    /**
     * Отправить, если доставка ещё ждёт.
     */
    public function handle(): void
    {
        $claimed = Delivery::query()->whereKey($this->delivery->id)->where('status', 'pending')
            ->update(['status' => 'sending', 'started_at' => now(), 'error' => null]);

        if ($claimed === 0) {
            return;
        }

        $delivery = $this->delivery->refresh();
        $publication = $delivery->publication;
        $channel = $delivery->channel;

        try {
            if (! $channel->enabled) {
                throw new MessengerFailed(__('fastleo::publishing.channel_disabled', [], Fastleo::LOCALE));
            }

            $done = [...($delivery->message_ids ?? []), 'media' => $delivery->media_tokens];
            $channel->bot()->publish($channel->chat_id, $publication->html(), $publication->imageFiles(), $publication->button(), $done, function (array $step, string $layout) use ($delivery): void {
                if (array_key_exists('media', $step)) {
                    $delivery->media_tokens = is_array($step['media']) ? array_values(array_filter($step['media'], is_string(...))) : null;
                    $delivery->layout = $layout;
                    $delivery->save();

                    return;
                }

                /** @var array<string, string|list<string>> $step */
                $delivery->remember($step, $layout);
            });

            $delivery->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (MessengerFailed $exception) {
            if ($exception->retryAfter !== null && $this->job !== null && $this->attempts() < 5) {
                $delivery->update(['status' => 'pending', 'error' => $exception->getMessage()]);
                $this->release($exception->retryAfter);

                return;
            }

            $delivery->update(['status' => $exception->uncertain ? 'unknown' : 'failed', 'error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);
            $delivery->update(['status' => 'failed', 'error' => __('fastleo::publishing.delivery_crashed', [], Fastleo::LOCALE)]);
        }

        $this->finish($publication);
    }

    /**
     * Задача упала совсем (таймаут, процесс убит): зависшая доставка помечается ошибкой.
     */
    public function failed(?Throwable $exception): void
    {
        Delivery::query()->whereKey($this->delivery->id)->where('status', 'sending')
            ->update(['status' => 'unknown', 'error' => __('fastleo::publishing.delivery_crashed', [], Fastleo::LOCALE)]);

        $publication = Publication::query()->find($this->delivery->publication_id);

        if ($publication instanceof Publication) {
            $this->finish($publication);
        }
    }

    /**
     * Обновить статус публикации; если что-то не дошло, сказать автору.
     */
    private function finish(Publication $publication): void
    {
        $publication->refreshStatus();

        if (! in_array($publication->status, ['failed', 'partial', 'unknown'], true) || $publication->user_id === null) {
            return;
        }

        $author = Fastleo::guard()->getProvider()->retrieveById($publication->user_id);
        $resource = PublicationResource::registered();

        if ($author instanceof Authenticatable) {
            Fastleo::notify($author, FastleoMessage::make(__('fastleo::publishing.notify_failed', [], Fastleo::LOCALE))
                ->body(__('fastleo::publishing.status_'.$publication->status, [], Fastleo::LOCALE))
                ->url($resource instanceof \Fastleo\Resource ? route('fastleo.resources.show', [$resource::slug(), $publication->id], false) : null)
                ->icon('send')
                ->level('danger'));
        }
    }
}
