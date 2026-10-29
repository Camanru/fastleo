<?php

declare(strict_types=1);

use Fastleo\Http\Controllers\RobotsController;
use Illuminate\Support\Facades\Route;

/*
 * Публичный robots.txt. Подключается провайдером, только если fastleo.robots.enabled = true, —
 * иначе маршрут занял бы адрес /robots.txt, который сайт мог описать сам.
 */
Route::get('robots.txt', RobotsController::class)->name('fastleo.robots');
