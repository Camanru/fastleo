<?php

declare(strict_types=1);

namespace Fastleo\Leads;

use Fastleo\Fastleo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Приём заявок с формы сайта (`POST /leads`, ключ `fastleo.leads.form`).
 */
final class LeadController extends Controller
{
    /**
     * Сохранить заявку и сообщить пользователям панели. Бот получает тот же ответ, но заявка не сохраняется;
     * живой посетитель, отправивший форму слишком быстро или с устаревшей страницы, видит ошибку и может повторить.
     */
    public function store(StoreLeadRequest $request): RedirectResponse
    {
        if (! $request->isBot() && $request->isOutOfTime()) {
            return back()->withInput()->withErrors(['form_token' => __('fastleo::leads.form_too_fast', [], Fastleo::LOCALE)]);
        }

        if (! $request->isBot()) {
            $data = $request->validated();

            $lead = Lead::query()->create([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'message' => $data['message'] ?? null,
                'status' => LeadStatus::New,
                'source' => $this->sourcePath($request),
            ]);

            LeadNotifier::send($lead);
        }

        return back()->with('lead_sent', true);
    }

    /**
     * Путь страницы, с которой отправлена форма (из заголовка Referer); не путь или не UTF-8 — null.
     */
    private function sourcePath(StoreLeadRequest $request): ?string
    {
        $path = parse_url((string) $request->headers->get('referer'), PHP_URL_PATH);

        return is_string($path) && $path !== '' && mb_check_encoding($path, 'UTF-8') ? Str::limit($path, 500, '') : null;
    }
}
