<?php

declare(strict_types=1);

use Fastleo\Leads\AuthenticateLeadApiKey;
use Fastleo\Leads\LeadApiController;
use Illuminate\Support\Facades\Route;

/*
 * Приём заявок с других сайтов по API (`fastleo.leads.keys`). Запрос приходит с чужого сервера, поэтому маршрут в группе `api`:
 * без сессии, cookie и CSRF (иначе каждый запрос создавал бы сессию, а CSRF отвечал бы 419), но с проверкой «Закрытия сайта»
 * (при закрытом сайте ответ 503). Вся проверка ключа — в `AuthenticateLeadApiKey`.
 */
Route::post(config()->string('fastleo.leads.api_path', 'api/leads'), [LeadApiController::class, 'store'])
    ->middleware(['api', AuthenticateLeadApiKey::class])
    ->name('fastleo-leads.api');
