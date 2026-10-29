<?php

declare(strict_types=1);

namespace Fastleo\SoftNetica;

use Closure;
use Fastleo\Auth\FailedLogins;
use Fastleo\Auth\LoginRecord;
use Fastleo\Auth\TwoFactor;
use Fastleo\Fastleo;
use Fastleo\Health\HealthChecks;
use Fastleo\Support\ServerReport;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Тело отчёта для SoftNetica: версии, проверки сайта и сведения о входах в админку.
 *
 * Только поля, которые принимает панель (`versions`, `checks`, `admin_security`). В отчёт не попадают
 * адреса электронной почты, IP-адреса, пути, пароли и ключи.
 */
final readonly class SyncReport
{
    public function __construct(private HealthChecks $checks, private TwoFactor $twoFactor) {}

    /**
     * Собрать отчёт. Каждая часть собирается отдельно: сбой одной (например, не прогнаны миграции или упала база)
     * попадает в журнал ошибок, а отчёт всё равно уходит — без `versions` и `admin_security` (они необязательны)
     * и с пустым `checks` (обязателен, но может быть пустым).
     *
     * @return array{versions?: array<string, string>, checks: list<array<string, string>>, admin_security?: array<string, int>}
     */
    public function build(): array
    {
        $report = ['checks' => $this->guarded(fn (): array => $this->checks->collect()) ?? []];

        $versions = $this->guarded(fn (): array => array_map(fn (string $version): string => Str::limit($version, 64, ''), ServerReport::versions()));
        $security = $this->guarded(fn (): array => $this->adminSecurity());

        return [
            ...($versions === null ? [] : ['versions' => $versions]),
            ...$report,
            ...($security === null ? [] : ['admin_security' => $security]),
        ];
    }

    /**
     * Выполнить часть отчёта; исключение — в журнал ошибок, результат `null`.
     *
     * @template T of array<mixed>
     *
     * @param  Closure(): T  $part
     * @return T|null
     */
    private function guarded(Closure $part): ?array
    {
        try {
            return $part();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Сведения о входах: сколько администраторов, сколько без 2FA, неудачные входы и входы с новых адресов за час.
     *
     * @return array{admins: int, admins_without_two_factor: int, failed_logins_last_hour: int, new_address_logins_last_hour: int}
     */
    private function adminSecurity(): array
    {
        $admins = Fastleo::admins();

        return [
            'admins' => $this->limit(count($admins)),
            'admins_without_two_factor' => $this->limit(count(array_filter($admins, fn (Authenticatable $admin): bool => ! $this->twoFactor->isEnabled($admin)))),
            'failed_logins_last_hour' => $this->limit(FailedLogins::lastHour()),
            'new_address_logins_last_hour' => $this->limit($this->newAddressLogins()),
        ];
    }

    /**
     * Сколько администраторов за последний час вошли с адреса, с которого раньше не входили (при этом входили и раньше).
     */
    private function newAddressLogins(): int
    {
        $count = 0;
        $seen = [];

        foreach (LoginRecord::query()->where('created_at', '>=', now()->subHour())->oldest('created_at')->get() as $record) {
            $key = $record->user_id.'|'.$record->ip;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $earlier = LoginRecord::query()->where('user_id', $record->user_id)->where('created_at', '<', $record->created_at);

            if ($earlier->exists() && ! $earlier->where('ip', $record->ip)->exists()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Панель принимает целые от 0 до 1 000 000.
     */
    private function limit(int $value): int
    {
        return max(0, min(1_000_000, $value));
    }
}
