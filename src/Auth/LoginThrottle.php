<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use Illuminate\Cache\RateLimiter;

/**
 * Лимиты попыток входа в админку.
 *
 * Три счётчика: 5 в минуту на пару «аккаунт + IP», 20 в минуту на IP
 * и 50 в час на аккаунт с любых адресов (перебор пароля одной учётной записи
 * с многих IP). Аккаунт — номер найденного пользователя или введённый email,
 * если пользователя нет: разные написания одного адреса («ádmin@» и «admin@»)
 * не получают каждый свой счётчик.
 */
final readonly class LoginThrottle
{
    /** Попыток в минуту на пару «аккаунт + IP». */
    public const int PER_ACCOUNT_AND_IP = 5;

    /** Попыток в минуту с одного IP. */
    public const int PER_IP = 20;

    /** Попыток в час на один аккаунт с любых IP. */
    public const int PER_ACCOUNT_HOURLY = 50;

    /**
     * @param  RateLimiter  $limiter  счётчики Laravel в кеше приложения
     */
    public function __construct(private RateLimiter $limiter) {}

    /**
     * Сколько секунд ждать до следующей попытки или null, если входить можно.
     *
     * ```php
     * $throttle->availableIn('id:1', $request->ip()); // null или 42
     * ```
     */
    public function availableIn(string $account, string $ip): ?int
    {
        foreach ($this->limits($account, $ip) as $key => [$maxAttempts]) {
            if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
                return $this->limiter->availableIn($key);
            }
        }

        return null;
    }

    /**
     * Засчитать попытку и сразу сказать, не вышла ли она за лимит. Вызывается до проверки пароля.
     *
     * Решает число, которое вернул атомарный инкремент в кеше: пачка одновременных запросов,
     * прошедших `availableIn()`, не проскочит мимо счётчика — лишние получают отказ.
     *
     * ```php
     * $seconds = $throttle->availableIn($account, $ip) ?? $throttle->hit($account, $ip); // null — можно проверять пароль
     * ```
     */
    public function hit(string $account, string $ip): ?int
    {
        $exceeded = null;

        foreach ($this->limits($account, $ip) as $key => [$maxAttempts, $decaySeconds]) {
            if ($this->limiter->hit($key, $decaySeconds) > $maxAttempts) {
                $exceeded = max($exceeded ?? 0, $this->limiter->availableIn($key));
            }
        }

        return $exceeded;
    }

    /**
     * Сбросить счётчики аккаунта после успешного входа.
     *
     * Счётчик IP не трогается: вошедший администратор не должен получать свежий лимит
     * на перебор чужих аккаунтов со своего адреса.
     */
    public function clear(string $account, string $ip): void
    {
        foreach (array_keys($this->limits($account, $ip)) as $key) {
            if (! str_starts_with($key, 'fastleo-login-ip:')) {
                $this->limiter->clear($key);
            }
        }
    }

    /**
     * Снять часовую блокировку пользователя: и ввода пароля (`id:N`), и шага кода 2FA (`2fa:N`).
     * Её не снимает вход с другого адреса; для команды `fastleo:unlock`.
     * Минутные счётчики и счётчик IP сами обнуляются через минуту.
     *
     * ```php
     * $throttle->unlock('1');
     * ```
     */
    public function unlock(string $userId): void
    {
        $this->limiter->clear('fastleo-login-account:id:'.$userId);
        $this->limiter->clear('fastleo-login-account:2fa:'.$userId);
    }

    /**
     * Ключи счётчиков с лимитом и временем жизни в секундах.
     *
     * @return array<string, array{int, int}>
     */
    private function limits(string $account, string $ip): array
    {
        return [
            'fastleo-login:'.$account.'|'.$ip => [self::PER_ACCOUNT_AND_IP, 60],
            'fastleo-login-ip:'.$ip => [self::PER_IP, 60],
            'fastleo-login-account:'.$account => [self::PER_ACCOUNT_HOURLY, 3600],
        ];
    }
}
