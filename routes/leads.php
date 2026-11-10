<?php

declare(strict_types=1);

use Fastleo\Leads\LeadController;
use Illuminate\Support\Facades\Route;

/*
 * Форма заявки на сайте (`fastleo.leads.form`): группа `web` (сессия и CSRF, метка формы привязана к сессии посетителя).
 * Не больше 5 заявок за 10 минут с одного адреса; свой ключ `leads` — лимит не делится с другими маршрутами сайта.
 */
Route::middleware('web')->group(function (): void {
    Route::post(config()->string('fastleo.leads.path', 'leads'), [LeadController::class, 'store'])
        ->middleware('throttle:5,10,leads')
        ->name('fastleo-leads.store');
});
