<?php

declare(strict_types=1);

namespace Fastleo\SoftNetica;

use Composer\InstalledVersions;
use Fastleo\Fastleo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Обмен с SoftNetica: отправка отчёта о сайте и получение данных клиента для виджета и раздела.
 *
 * `POST {url}/api/fastleo/sync` с токеном в заголовке `Authorization`. Метод `run()` не бросает исключений: любой сбой
 * сети или ответа — это результат `SyncResult`, сайт и админка от него не страдают. Токен не попадает в журнал.
 */
final readonly class Synchronizer
{
    /** Ответ больше стольких байт считается ошибкой протокола. */
    private const int MAX_BODY = 524_288;

    public function __construct(private SyncReport $report, private SyncState $state) {}

    /**
     * Отправить отчёт и обработать ответ панели. Не бросает исключений: любой сбой — результат `SyncResult`,
     * а неожиданная ошибка (не сеть) попадает в журнал ошибок сайта.
     */
    public function run(): SyncResult
    {
        try {
            $token = Connection::token();

            if (! Connection::enabled() || $token === '') {
                return SyncResult::NotConfigured;
            }

            $endpoint = Connection::endpoint();

            if ($endpoint === null) {
                return SyncResult::BadUrl;
            }

            $body = $this->report->build();

            try {
                $response = Http::connectTimeout(5)
                    ->timeout(10)
                    ->withToken($token)
                    ->acceptJson()
                    ->asJson()
                    ->withUserAgent('Fastleo/'.$this->version())
                    ->withoutRedirecting()
                    ->withOptions(['progress' => self::limitDownload(...)])
                    ->post($endpoint.'/api/fastleo/sync', $body);
            } catch (ConnectionException|RequestException|RuntimeException) { // нет связи или ответ слишком большой (limitDownload), в том числе с кодом 4xx/5xx
                return $this->failure($token, SyncResult::Offline);
            }

            return $this->handle($token, $response);
        } catch (Throwable $exception) {
            report($exception);

            return SyncResult::Offline;
        }
    }

    /**
     * Прервать загрузку, как только ответ панели обещает больше `MAX_BODY` байт или уже скачано больше:
     * огромный ответ не должен целиком оказаться в памяти. Колбэк `progress` клиента HTTP.
     *
     * @throws RuntimeException ответ слишком большой
     */
    public static function limitDownload(int $downloadTotal, int $downloaded): void
    {
        if ($downloadTotal > self::MAX_BODY || $downloaded > self::MAX_BODY) {
            throw new RuntimeException('Ответ SoftNetica больше '.self::MAX_BODY.' байт.');
        }
    }

    /**
     * Разобрать ответ панели.
     */
    private function handle(#[SensitiveParameter] string $token, Response $response): SyncResult
    {
        return match (true) {
            $response->status() === 200 => $this->accept($token, $response),
            $response->status() === 401 => $this->reject($token),
            $response->status() === 429 => $this->failure($token, SyncResult::Throttled),
            $response->status() === 422 => $this->invalid($token, $response),
            default => $this->failure($token, SyncResult::Offline),
        };
    }

    /**
     * 200: сохранить данные клиента, если ответ по протоколу.
     */
    private function accept(#[SensitiveParameter] string $token, Response $response): SyncResult
    {
        if (strlen($response->body()) > self::MAX_BODY) {
            return $this->failure($token, SyncResult::Offline);
        }

        $json = $response->json();

        if (! is_array($json) || ($json['accepted'] ?? null) !== true || ! is_array($json['client'] ?? null)) {
            return $this->failure($token, SyncResult::Offline);
        }

        $this->state->recordConnected($token, $json['client']);

        return SyncResult::Connected;
    }

    /**
     * 401: данные клиента стираются, запросы прекращаются до смены токена.
     */
    private function reject(#[SensitiveParameter] string $token): SyncResult
    {
        $this->state->recordRejected($token);

        return SyncResult::Rejected;
    }

    /**
     * 422: ошибка схемы (ошибка пакета) — в журнал, данные остаются.
     */
    private function invalid(#[SensitiveParameter] string $token, Response $response): SyncResult
    {
        $errors = $response->json('errors');
        $details = is_array($errors) ? json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : '';

        Log::warning('SoftNetica отклонила отчёт fastleo (422): '.mb_substr(str_replace($token, '***', is_string($details) ? $details : ''), 0, 1000));

        return $this->failure($token, SyncResult::Invalid);
    }

    /**
     * Запомнить неудачную попытку, не трогая данные.
     */
    private function failure(#[SensitiveParameter] string $token, SyncResult $result): SyncResult
    {
        $this->state->recordAttempt($token, $result);

        return $result;
    }

    /**
     * Версия fastleo для User-Agent.
     */
    private function version(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('camanru/fastleo') ?? 'dev';
        } catch (Throwable) {
            return 'dev';
        }
    }
}
