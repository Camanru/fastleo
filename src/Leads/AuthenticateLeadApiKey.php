<?php

declare(strict_types=1);

namespace Fastleo\Leads;

use Closure;
use Fastleo\Fastleo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Проверка запроса на приём заявок по API до разбора тела: размер, ключ, лимиты, адрес сайта. Ответы всегда JSON.
 *
 * Порядок важен. Неизвестный ключ считается по адресу отправителя только при неудаче: настоящий сайт за общим прокси не
 * упрётся в чужие неудачные попытки. Лимит ключа считается раньше сравнения (`hit()` атомарен при параллельных запросах).
 * Ключ берётся только из заголовка `Authorization: Bearer` — не из адреса и не из тела, которые попадают в журналы.
 */
final class AuthenticateLeadApiKey
{
    /** Самое большое тело запроса, байт. */
    private const int MAX_BODY = 16384;

    /** Неудачных попыток с одного адреса в минуту. */
    private const int FAILED_PER_MINUTE = 20;

    /** Запросов с одним ключом в минуту. */
    private const int KEY_PER_MINUTE = 30;

    /** Запросов с одним ключом в сутки. */
    private const int KEY_PER_DAY = 1000;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        $response = $this->check($request) ?? $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Ответ с отказом или null, если запрос можно принять (ключ лежит в `$request->attributes['lead_api_key']`).
     */
    private function check(Request $request): ?JsonResponse
    {
        if ((int) $request->header('Content-Length') > self::MAX_BODY || strlen($request->getContent()) > self::MAX_BODY) {
            return $this->refuse('too_large', 413, 'api_too_large');
        }

        $key = LeadApiKey::findActive((string) $request->bearerToken());

        if (! $key instanceof LeadApiKey) {
            return $this->unauthorized($request);
        }

        $minute = 'lead-api-key:'.$key->id;

        if (RateLimiter::hit($minute, 60) > self::KEY_PER_MINUTE) {
            return $this->tooMany($minute);
        }

        $day = 'lead-api-day:'.$key->id;

        if (RateLimiter::hit($day, 86400) > self::KEY_PER_DAY) {
            return $this->tooMany($day);
        }

        if (! $this->originAllowed($request, $key)) {
            return $this->refuse('forbidden', 403, 'api_forbidden');
        }

        $key->touchUsed();
        $request->attributes->set('lead_api_key', $key);

        return null;
    }

    /**
     * Неизвестный, выключенный или пропущенный ключ: одинаковый ответ; неудачи с одного адреса ограничены.
     */
    private function unauthorized(Request $request): JsonResponse
    {
        $failed = 'lead-api-failed:'.sha1((string) $request->ip());

        if (RateLimiter::tooManyAttempts($failed, self::FAILED_PER_MINUTE)) {
            return $this->tooMany($failed);
        }

        RateLimiter::hit($failed, 60);

        return $this->refuse('unauthorized', 401, 'api_unauthorized')->header('WWW-Authenticate', 'Bearer');
    }

    /**
     * Ответ «слишком много запросов» с подсказкой, через сколько секунд повторить.
     */
    private function tooMany(string $limiter): JsonResponse
    {
        return $this->refuse('too_many_requests', 429, 'api_too_many')->header('Retry-After', (string) max(1, RateLimiter::availableIn($limiter)));
    }

    /**
     * Запрос из браузера (есть заголовок `Origin`) должен прийти с сайта, записанного у ключа; без адреса у ключа и без заголовка
     * (запрос с сервера) проверки нет. Это не защита: заголовок подделывается вне браузера.
     */
    private function originAllowed(Request $request, LeadApiKey $key): bool
    {
        $origin = $request->headers->get('Origin');

        if ($origin === null || blank($key->site)) {
            return true;
        }

        $allowed = $this->host((string) $key->site);

        return $allowed !== '' && $this->host($origin) === $allowed;
    }

    /**
     * Хост из адреса или из самого значения, строчными, без `www.`; пусто — хоста нет (например `Origin: null`).
     */
    private function host(string $value): string
    {
        $host = parse_url(str_contains($value, '://') ? $value : 'https://'.$value, PHP_URL_HOST);

        return is_string($host) ? preg_replace('/\Awww\./', '', strtolower($host)) ?? '' : '';
    }

    /**
     * Ответ с отказом: код ответа, статус для программы и текст для человека.
     */
    private function refuse(string $status, int $code, string $message): JsonResponse
    {
        return response()->json(['status' => $status, 'message' => __('fastleo::leads.'.$message, [], Fastleo::LOCALE)], $code);
    }
}
