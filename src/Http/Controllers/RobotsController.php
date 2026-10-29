<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Support\Robots;
use Illuminate\Http\Response;

/**
 * Публичный `/robots.txt` (без входа). Маршрут есть, только если `fastleo.robots.enabled` = true.
 */
final class RobotsController
{
    /**
     * Текст robots.txt.
     */
    public function __invoke(): Response
    {
        return response(Robots::text(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
