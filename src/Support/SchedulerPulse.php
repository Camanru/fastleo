<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * «Пульс» планировщика Laravel: метка времени последнего запуска `schedule:run` в кеше приложения.
 * Без него не работают задачи пакета (`fastleo:prune`, синхронизация SoftNetica); дашборд и `fastleo:doctor`
 * предупреждают, если метка устарела.
 */
final class SchedulerPulse
{
    /** Метке старше стольких секунд не верим: cron запускает `schedule:run` каждую минуту. */
    public const int STALE_SECONDS = 600;

    private const string KEY = 'fastleo.schedule-ran';

    /**
     * Записать запуск (вызывается из события консоли при старте `schedule:run`).
     *
     * Сбой кеша не пробрасывается: слушатель срабатывает до `schedule:run`, и исключение остановило бы все задачи сайта.
     */
    public static function beat(): void
    {
        try {
            Cache::put(self::KEY, now()->getTimestamp(), 86400);
        } catch (Throwable) {
            // Метки не будет — дашборд и `fastleo:doctor` скажут, что планировщик не запускался.
        }
    }

    /**
     * Когда планировщик запускался в последний раз (unix-время) или null, если меток нет.
     */
    public static function lastRun(): ?int
    {
        $time = Cache::get(self::KEY);

        return is_int($time) ? $time : null;
    }

    /**
     * Метки нет или она старше `STALE_SECONDS`: планировщик, судя по всему, не запущен.
     */
    public static function isStale(): bool
    {
        $time = self::lastRun();

        return $time === null || now()->getTimestamp() - $time > self::STALE_SECONDS;
    }
}
