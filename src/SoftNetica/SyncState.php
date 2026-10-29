<?php

declare(strict_types=1);

namespace Fastleo\SoftNetica;

use Carbon\CarbonImmutable;
use Fastleo\Support\SettingsStore;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

/**
 * Состояние связи с SoftNetica: последний ответ панели (данные клиента), время синхронизации, итог последней
 * попытки и отметка «токен отклонён». Лежит одной служебной строкой таблицы `fastleo_settings` (адрес `_softnetica`),
 * поэтому переживает очистку кеша и деплой. Строка не входит в `fastleo_setting()`.
 *
 * Данные привязаны к токену по его хешу: сменили токен — старые данные и отметка «отклонён» не действуют.
 *
 * @phpstan-type State array{token_hash: ?string, client: ?array<mixed>, synced_at: ?string, attempted_at: ?string, result: ?string, rejected_hash: ?string}
 */
final class SyncState
{
    /** Адрес служебной строки в таблице настроек. */
    public const string SLUG = '_softnetica';

    /** Данные старше стольких минут считаются устаревшими («нет связи»). */
    public const int STALE_MINUTES = 30;

    /** @var State|null */
    private ?array $state = null;

    /**
     * Панель отклонила именно этот токен (401) — запросы не шлём, пока токен не сменят или связь не проверят вручную.
     */
    public function isRejected(#[SensitiveParameter] string $token): bool
    {
        $rejected = $this->load()['rejected_hash'];

        return $rejected !== null && hash_equals($rejected, $this->hash($token));
    }

    /**
     * Данные клиента из последнего ответа панели для текущего токена или null.
     *
     * @return array<mixed>|null
     */
    public function client(): ?array
    {
        $state = $this->load();

        return $this->matches($state) ? $state['client'] : null;
    }

    /**
     * Когда данные клиента получены в последний раз.
     */
    public function syncedAt(): ?CarbonImmutable
    {
        $state = $this->load();

        return $this->matches($state) ? $this->date($state['synced_at']) : null;
    }

    /**
     * Когда была последняя попытка синхронизации.
     */
    public function attemptedAt(): ?CarbonImmutable
    {
        $state = $this->load();

        return $this->matches($state) ? $this->date($state['attempted_at']) : null;
    }

    /**
     * Итог последней попытки.
     */
    public function lastResult(): ?SyncResult
    {
        $state = $this->load();

        return $this->matches($state) ? SyncResult::tryFrom($state['result'] ?? '') : null;
    }

    /**
     * Данные есть, но старше получаса — связь с SoftNetica потеряна.
     */
    public function isStale(): bool
    {
        $syncedAt = $this->syncedAt();

        return ! $syncedAt instanceof CarbonImmutable || $syncedAt->lt(now()->subMinutes(self::STALE_MINUTES));
    }

    /**
     * Панель приняла отчёт: сохранить данные клиента, снять отметку «отклонён».
     *
     * @param  array<mixed>  $client
     */
    public function recordConnected(#[SensitiveParameter] string $token, array $client): void
    {
        if (! $this->isCurrent($token)) {
            return;
        }

        $this->write([
            'token_hash' => $this->hash($token),
            'client' => $client,
            'synced_at' => now()->toIso8601String(),
            'attempted_at' => now()->toIso8601String(),
            'result' => SyncResult::Connected->value,
            'rejected_hash' => null,
        ]);
    }

    /**
     * Панель отклонила токен: стереть данные клиента и запомнить отказ.
     */
    public function recordRejected(#[SensitiveParameter] string $token): void
    {
        if (! $this->isCurrent($token)) {
            return;
        }

        $this->write([
            'token_hash' => $this->hash($token),
            'client' => null,
            'synced_at' => null,
            'attempted_at' => now()->toIso8601String(),
            'result' => SyncResult::Rejected->value,
            'rejected_hash' => $this->hash($token),
        ]);
    }

    /**
     * Неудачная попытка (нет связи, слишком часто, ошибка схемы): данные клиента и отказ остаются как были.
     */
    public function recordAttempt(#[SensitiveParameter] string $token, SyncResult $result): void
    {
        if (! $this->isCurrent($token)) {
            return;
        }

        $state = $this->load();
        $same = $this->matches($state);

        $this->write([
            'token_hash' => $this->hash($token),
            'client' => $same ? $state['client'] : null,
            'synced_at' => $same ? $state['synced_at'] : null,
            'attempted_at' => now()->toIso8601String(),
            'result' => $result->value,
            'rejected_hash' => $state['rejected_hash'],
        ]);
    }

    /**
     * Забыть всё (при отключении).
     */
    public function forget(): void
    {
        DB::table('fastleo_settings')->where('slug', self::SLUG)->delete();
        $this->state = $this->empty();
    }

    /**
     * Токен всё ещё тот, что записан в настройках. Пока шёл запрос к панели, токен могли сменить или стереть
     * («Отключить»): ответ на старый токен не должен вернуть данные в базу. Настройки читаются заново, мимо кеша.
     */
    private function isCurrent(#[SensitiveParameter] string $token): bool
    {
        resolve(SettingsStore::class)->flush();

        return hash_equals(Connection::token(), $token);
    }

    /**
     * Хеш токена для сравнения (самого токена в состоянии нет).
     */
    private function hash(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Данные принадлежат текущему токену.
     *
     * @param  State  $state
     */
    private function matches(array $state): bool
    {
        $token = Connection::token();

        return $token !== '' && $state['token_hash'] !== null && hash_equals($state['token_hash'], $this->hash($token));
    }

    /**
     * Прочитать состояние (один запрос на жизнь объекта).
     *
     * @return State
     */
    private function load(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }

        try {
            $json = DB::table('fastleo_settings')->where('slug', self::SLUG)->value('value');
        } catch (Throwable) {
            $json = null;
        }

        $decoded = is_string($json) ? json_decode($json, true) : null;
        $decoded = is_array($decoded) ? $decoded : [];
        $client = $decoded['client'] ?? null;

        return $this->state = [
            'token_hash' => $this->string($decoded['token_hash'] ?? null),
            'client' => is_array($client) ? $client : null,
            'synced_at' => $this->string($decoded['synced_at'] ?? null),
            'attempted_at' => $this->string($decoded['attempted_at'] ?? null),
            'result' => $this->string($decoded['result'] ?? null),
            'rejected_hash' => $this->string($decoded['rejected_hash'] ?? null),
        ];
    }

    /**
     * Записать состояние.
     *
     * @param  State  $state
     */
    private function write(array $state): void
    {
        DB::table('fastleo_settings')->upsert(
            [['slug' => self::SLUG, 'value' => json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'created_at' => now(), 'updated_at' => now()]],
            ['slug'],
            ['value', 'updated_at'],
        );

        $this->state = $state;
    }

    /**
     * Пустое состояние.
     *
     * @return State
     */
    private function empty(): array
    {
        return ['token_hash' => null, 'client' => null, 'synced_at' => null, 'attempted_at' => null, 'result' => null, 'rejected_hash' => null];
    }

    /**
     * Строка или null.
     */
    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Дата из строки ISO 8601 или null.
     */
    private function date(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
