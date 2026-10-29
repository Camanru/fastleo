<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

/**
 * Отложенная публикация: в назначенное время ставит в очередь отправку по всем ждущим каналам.
 *
 * Ждёт отрезками не длиннее 15 минут (у SQS задержка больше не бывает): проснувшись раньше срока, ставит
 * себя снова. Задача помнит время, на которое её поставили: если публикацию отменили или перенесли,
 * старая задача ничего не делает.
 *
 * ```php
 * StartPublication::schedule($publication); // после $publication->scheduled_at = …
 * ```
 */
#[DeleteWhenMissingModels]
final class StartPublication implements ShouldQueue
{
    use Queueable;

    /** Наибольшая задержка одного отрезка ожидания, секунд. */
    public const int MAX_DELAY = 900;

    public function __construct(public readonly Publication $publication, public readonly string $at) {}

    /**
     * Поставить ожидание до `scheduled_at` публикации (после фиксации транзакции).
     */
    public static function schedule(Publication $publication): void
    {
        $at = $publication->scheduled_at;

        if ($at === null) {
            return;
        }

        $wait = max(0, min(self::MAX_DELAY, (int) now()->diffInSeconds($at, false)));

        dispatch(new self($publication, $at->toIso8601String()))->delay(now()->addSeconds($wait))->afterCommit();
    }

    /**
     * Время пришло — отправить; рано — подождать ещё; отменили или перенесли — ничего.
     */
    public function handle(): void
    {
        $publication = $this->publication->refresh();

        if ($publication->status !== 'scheduled' || $publication->scheduled_at?->toIso8601String() !== $this->at) {
            return;
        }

        if (now()->lt($publication->scheduled_at)) {
            self::schedule($publication);

            return;
        }

        $publication->start();
    }
}
