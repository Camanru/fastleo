<?php

declare(strict_types=1);

namespace Fastleo\Leads;

use Fastleo\Fastleo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Приём заявок с других сайтов (`POST /api/leads`, ключ `fastleo.leads.keys`). Ключ, размер и лимиты проверил
 * `AuthenticateLeadApiKey` до разбора тела; здесь поля, повтор и создание заявки.
 */
final class LeadApiController extends Controller
{
    /** Такая же заявка за это число секунд — повтор. */
    private const int DUPLICATE_SECONDS = 600;

    /**
     * Принять заявку. 201 — создана, 200 `duplicate` — такая же уже пришла, 422 — поля не прошли проверку (JSON с `errors`).
     */
    public function store(Request $request): JsonResponse
    {
        $key = $request->attributes->get('lead_api_key');
        abort_unless($key instanceof LeadApiKey, 401);

        // JSON-клиенты часто присылают телефон числом; вложенное (массив) остаётся как есть и не проходит правило `string`.
        $input = array_map(fn (mixed $value): mixed => is_scalar($value) ? (string) $value : $value, $request->only(['name', 'phone', 'email', 'message', 'page', 'form']));

        $data = Validator::make($input, [
            'phone' => ['required', 'string', 'max:50', StoreLeadRequest::PHONE],
            'name' => ['nullable', 'string', 'max:100', StoreLeadRequest::TEXT],
            'email' => ['bail', 'nullable', 'string', 'max:255', 'email'],
            'message' => ['nullable', 'string', 'max:2000', StoreLeadRequest::TEXT],
            'page' => ['nullable', 'string', 'url:http,https', 'max:500', StoreLeadRequest::TEXT],
            'form' => ['nullable', 'string', 'max:100', StoreLeadRequest::TEXT],
        ])->validate();

        // `add` атомарен: два одинаковых запроса одновременно дадут одну заявку.
        $fingerprint = sha1(serialize([$key->id, $data['phone'], $data['message'] ?? null, $data['form'] ?? null]));

        $marker = 'lead-dup:'.$fingerprint;

        if (! Cache::add($marker, true, self::DUPLICATE_SECONDS)) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            $lead = Lead::query()->create([
                'name' => $data['name'] ?? __('fastleo::leads.no_name', [], Fastleo::LOCALE),
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'message' => $data['message'] ?? null,
                'source' => $data['page'] ?? null,
                'site' => Str::limit($key->name, 100, ''),
                'form' => $data['form'] ?? null,
                'status' => LeadStatus::New,
            ]);
        } catch (Throwable $e) {
            // Заявка не сохранилась (сбой базы): метку снимаем, иначе повтор клиента получит «duplicate» и заявка потеряется молча.
            Cache::forget($marker);

            throw $e;
        }

        LeadNotifier::send($lead, 'leads-notify-api:'.$key->id);

        return response()->json(['status' => 'created', 'result' => $lead->getKey()], 201);
    }
}
