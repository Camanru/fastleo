<?php

declare(strict_types=1);

namespace Fastleo\Http\Middleware;

use Closure;
use Fastleo\Activity\ActivityRecorder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Включает журнал изменений на время запроса администратора и выключает после ответа.
 */
final readonly class RecordActivity
{
    /**
     * @param  ActivityRecorder  $recorder  запись журнала
     */
    public function __construct(private ActivityRecorder $recorder) {}

    /**
     * Пока выполняется запрос, изменения моделей разделов попадают в журнал.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->recorder->start();

        try {
            return $next($request);
        } finally {
            $this->recorder->stop();
        }
    }
}
