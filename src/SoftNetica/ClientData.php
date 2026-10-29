<?php

declare(strict_types=1);

namespace Fastleo\SoftNetica;

use Carbon\CarbonImmutable;
use Fastleo\Fastleo;
use Throwable;

/**
 * Данные клиента из ответа SoftNetica в безопасном виде для вывода.
 *
 * Ответ пришёл из внешнего сервиса, поэтому разбирается при каждом чтении, а не при записи: любые значения приводятся
 * к ожидаемым типам. Строки — без управляющих символов и обрезаны; цвет — только `ok|warn|crit|mute` (иначе `mute`);
 * ссылки — только `https://` на хосты кабинета из `fastleo.softnetica.cabinet_hosts` (иначе `null`); неверные даты —
 * `null`; ночей не больше 14, счетов и отчётов не больше 12. Любое поле может быть `null` — шаблон пишет «нет данных».
 * Неизвестные поля игнорируются.
 *
 * @phpstan-type Tone 'ok'|'warn'|'crit'|'mute'
 * @phpstan-type Expiry array{at: ?CarbonImmutable, days: ?int, tone: Tone}
 * @phpstan-type DomainExpiry array{at: ?CarbonImmutable, days: ?int, tone: Tone, paid_by_client: ?bool}
 * @phpstan-type Night array{date: CarbonImmutable, copies: array<string, ?string>}
 * @phpstan-type Restore array{tested_on: ?CarbonImmutable, result: ?string, tone: Tone}
 * @phpstan-type Payment array{paid_at: ?CarbonImmutable, amount: ?int, method: ?string}
 * @phpstan-type Invoice array{id: ?string, act_available: bool, title: ?string, period_from: ?CarbonImmutable, period_to: ?CarbonImmutable, amount: ?int, remaining: ?int, due_at: ?CarbonImmutable, status_label: ?string, tone: Tone, payments: list<Payment>, url: ?string}
 * @phpstan-type Billing array{next: ?array{due_at: ?CarbonImmutable, amount: ?int}, overdue: ?array{amount: ?int, since: ?CarbonImmutable}, invoices: list<Invoice>, url: ?string}
 * @phpstan-type Report array{month: string, label: string, current: bool, url: ?string}
 */
final readonly class ClientData
{
    /** Допустимые состояния копии за ночь. */
    private const array COPY_STATES = ['done', 'partial', 'missed', 'pending'];

    /**
     * @param  array{tone: Tone, label: ?string}  $status
     * @param  array{last_success_at: ?CarbonImmutable, tone: Tone}|null  $backup
     * @param  Expiry|null  $ssl
     * @param  DomainExpiry|null  $domainExpiry
     * @param  array{problem: ?bool, checked_at: ?CarbonImmutable}|null  $security
     * @param  list<array{target: string, label: string}>  $targets
     * @param  list<Night>  $nights
     * @param  array{automatic: ?Restore, manual: ?Restore}|null  $restore
     * @param  Billing|null  $billing
     * @param  list<Report>  $reports
     */
    private function __construct(
        public ?string $domain,
        public array $status,
        public ?float $uptime,
        public ?array $backup,
        public ?array $ssl,
        public ?array $domainExpiry,
        public ?array $security,
        public array $targets,
        public array $nights,
        public ?array $restore,
        public ?array $billing,
        public array $reports,
        public ?string $cabinetUrl,
    ) {}

    /**
     * Разобрать ответ панели (поле `client`).
     *
     * @param  array<mixed>  $raw
     */
    public static function from(array $raw): self
    {
        $status = self::map($raw['status'] ?? null);
        $backupNights = self::map($raw['backup_nights'] ?? null);
        $targets = self::targets($backupNights['targets'] ?? null);

        return new self(
            domain: self::text($raw['domain'] ?? null, 255),
            status: ['tone' => self::tone($status['tone'] ?? null), 'label' => self::text($status['label'] ?? null, 100)],
            uptime: self::percent($raw['uptime_30d'] ?? null),
            backup: self::backup($raw['backup'] ?? null),
            ssl: self::expiry($raw['ssl'] ?? null, 'expires_at'),
            domainExpiry: self::domainExpiry($raw['domain_expiry'] ?? null),
            security: self::security($raw['security'] ?? null),
            targets: $targets,
            nights: self::nights($backupNights['nights'] ?? null, $targets),
            restore: self::restore($raw['restore'] ?? null),
            billing: self::billing($raw['billing'] ?? null),
            reports: self::reports($raw['reports'] ?? null),
            cabinetUrl: self::link($raw['cabinet_url'] ?? null),
        );
    }

    /**
     * Последняя ночь в списке (самая поздняя по дате) или null.
     *
     * @return Night|null
     */
    public function lastNight(): ?array
    {
        return $this->nights === [] ? null : $this->nights[array_key_last($this->nights)];
    }

    /**
     * Свежая ли последняя ночь: сегодняшняя или вчерашняя по часовому поясу админки.
     */
    public function lastNightIsFresh(): bool
    {
        $night = $this->lastNight();

        return $night !== null && $night['date']->greaterThanOrEqualTo(now()->timezone(Fastleo::timezone())->subDay()->startOfDay()->shiftTimezone('UTC'));
    }

    /**
     * Цвет итога копий за ночь: все сделаны — `ok`, часть — `warn`, пропущена — `crit`, иначе `mute`.
     *
     * @param  Night  $night
     * @return Tone
     */
    public static function nightTone(array $night): string
    {
        $states = array_values($night['copies']);

        return match (true) {
            $states === [] => 'mute',
            in_array('missed', $states, true) => 'crit',
            in_array('partial', $states, true) => 'warn',
            ! in_array(null, $states, true) && array_unique($states) === ['done'] => 'ok',
            default => 'mute',
        };
    }

    /**
     * Тон бейджа компонента `<x-fastleo::badge>` для цвета SoftNetica.
     *
     * @param  Tone  $tone
     */
    public static function badgeTone(string $tone): string
    {
        return match ($tone) {
            'ok' => 'success',
            'warn' => 'warning',
            'crit' => 'danger',
            default => 'neutral',
        };
    }

    /**
     * Сколько копий за ночь сделано: «2 / 3».
     *
     * @param  Night  $night
     */
    public static function nightSummary(array $night): string
    {
        return count(array_filter($night['copies'], fn (?string $state): bool => $state === 'done')).' / '.count($night['copies']);
    }

    /**
     * Сумма в рублях: «12 000 ₽» или «нет данных».
     */
    public static function money(?int $amount): string
    {
        return $amount === null ? __('fastleo::softnetica.no_data', [], Fastleo::LOCALE) : number_format($amount, 0, ',', "\u{00A0}")."\u{00A0}₽";
    }

    /**
     * Дата для вывода: «31.01.2026» (для даты со временем — в часовом поясе админки) или «нет данных».
     */
    public static function show(?CarbonImmutable $date, bool $time = false): string
    {
        return $date?->format($time ? 'd.m.Y H:i' : 'd.m.Y') ?? __('fastleo::softnetica.no_data', [], Fastleo::LOCALE);
    }

    /**
     * Короткая строка (скаляр) без управляющих символов, не длиннее `$limit`; пустая — `null`.
     */
    private static function text(mixed $value, int $limit = 200): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}\s]+/u', ' ', $value));

        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1).'…' : $value;
    }

    /**
     * Цвет из белого списка.
     *
     * @return Tone
     */
    private static function tone(mixed $value): string
    {
        return match ($value) {
            'ok' => 'ok',
            'warn' => 'warn',
            'crit' => 'crit',
            default => 'mute',
        };
    }

    /**
     * Целое число (в том числе из числовой строки) или null.
     */
    private static function integer(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d{1,15}$/', $value) === 1) || (is_float($value) && is_finite($value) && abs($value) < 1e15) ? (int) $value : null;
    }

    /**
     * Проценты 0–100 или null.
     */
    private static function percent(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? max(0.0, min(100.0, (float) $value)) : null;
    }

    /**
     * Ассоциативный массив (список и скаляры дают пустой массив).
     *
     * @return array<mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Дата или момент из строки ISO 8601: только дата — как есть, со временем — в часовом поясе админки.
     */
    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || strlen($value) > 40) {
            return null;
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

                return $day instanceof CarbonImmutable && $day->format('Y-m-d') === $value ? $day : null;
            }

            return preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value) === 1 ? CarbonImmutable::parse($value)->timezone(Fastleo::timezone()) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Ссылка в кабинет: только `https://` на хост кабинета из конфига, без логина, порта и странных символов.
     */
    public static function link(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 2000 || preg_match('/^[\x21-\x7E]+$/', $value) !== 1 || str_contains($value, '\\')) {
            return null;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! isset($parts['host']) || ! in_array(strtolower($parts['host']), Connection::cabinetHosts(), true) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $value;
    }

    /**
     * Блок «бэкап».
     *
     * @return array{last_success_at: ?CarbonImmutable, tone: Tone}|null
     */
    private static function backup(mixed $value): ?array
    {
        $backup = self::map($value);

        return $backup === [] ? null : ['last_success_at' => self::date($backup['last_success_at'] ?? null), 'tone' => self::tone($backup['tone'] ?? null)];
    }

    /**
     * Срок сертификата или домена.
     *
     * @return Expiry|null
     */
    private static function expiry(mixed $value, string $key): ?array
    {
        $expiry = self::map($value);

        return $expiry === [] ? null : ['at' => self::date($expiry[$key] ?? null), 'days' => self::integer($expiry['days_left'] ?? null), 'tone' => self::tone($expiry['tone'] ?? null)];
    }

    /**
     * Срок домена с признаком «оплачивает клиент».
     *
     * @return DomainExpiry|null
     */
    private static function domainExpiry(mixed $value): ?array
    {
        $expiry = self::expiry($value, 'expires_on');
        $paid = self::map($value)['paid_by_client'] ?? null;

        return $expiry === null ? null : [...$expiry, 'paid_by_client' => is_bool($paid) ? $paid : null];
    }

    /**
     * Блок «безопасность».
     *
     * @return array{problem: ?bool, checked_at: ?CarbonImmutable}|null
     */
    private static function security(mixed $value): ?array
    {
        $security = self::map($value);
        $problem = $security['problem'] ?? null;

        return $security === [] ? null : ['problem' => is_bool($problem) ? $problem : null, 'checked_at' => self::date($security['checked_at'] ?? null)];
    }

    /**
     * Копии, которые показываются по ночам.
     *
     * @return list<array{target: string, label: string}>
     */
    private static function targets(mixed $value): array
    {
        $targets = [];

        foreach (is_array($value) ? array_slice($value, 0, 6) : [] as $item) {
            $item = self::map($item);
            $target = self::text($item['target'] ?? null, 64);

            if ($target !== null && ! in_array($target, array_column($targets, 'target'), true)) {
                $targets[] = ['target' => $target, 'label' => self::text($item['label'] ?? null, 100) ?? $target];
            }
        }

        return $targets;
    }

    /**
     * Ночи, отсортированные по дате (не больше 14 последних).
     *
     * @param  list<array{target: string, label: string}>  $targets
     * @return list<Night>
     */
    private static function nights(mixed $value, array $targets): array
    {
        $nights = [];

        foreach (is_array($value) ? array_slice($value, 0, 60) : [] as $item) {
            $item = self::map($item);
            $date = is_string($item['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $item['date']) === 1 ? self::date($item['date']) : null;

            if (! $date instanceof CarbonImmutable) {
                continue;
            }

            $copies = [];
            $given = self::map($item['copies'] ?? null);

            foreach ($targets as $target) {
                $state = $given[$target['target']] ?? null;
                $copies[$target['target']] = is_string($state) && in_array($state, self::COPY_STATES, true) ? $state : null;
            }

            $nights[$date->format('Y-m-d')] = ['date' => $date, 'copies' => $copies];
        }

        ksort($nights);

        return array_values(array_slice($nights, -14));
    }

    /**
     * Проверки восстановления из копии.
     *
     * @return array{automatic: ?Restore, manual: ?Restore}|null
     */
    private static function restore(mixed $value): ?array
    {
        $restore = self::map($value);

        if ($restore === []) {
            return null;
        }

        $one = static function (mixed $item): ?array {
            $item = self::map($item);

            return $item === [] ? null : ['tested_on' => self::date($item['tested_on'] ?? null), 'result' => self::text($item['result'] ?? null, 100), 'tone' => self::tone($item['tone'] ?? null)];
        };

        return ['automatic' => $one($restore['automatic'] ?? null), 'manual' => $one($restore['manual'] ?? null)];
    }

    /**
     * Блок «счета»: не больше 12 счетов; `null` — сайт без клиента.
     *
     * @return Billing|null
     */
    private static function billing(mixed $value): ?array
    {
        $billing = self::map($value);

        if ($billing === []) {
            return null;
        }

        $summary = self::map($billing['summary'] ?? null);
        $next = self::map($summary['next'] ?? null);
        $overdue = self::map($summary['overdue'] ?? null);
        $invoices = [];

        foreach (is_array($billing['invoices'] ?? null) ? array_slice($billing['invoices'], 0, 12) : [] as $item) {
            $invoices[] = self::invoice(self::map($item));
        }

        return [
            'next' => $next === [] ? null : ['due_at' => self::date($next['due_at'] ?? null), 'amount' => self::integer($next['amount'] ?? null)],
            'overdue' => $overdue === [] ? null : ['amount' => self::integer($overdue['amount'] ?? null), 'since' => self::date($overdue['since'] ?? null)],
            'invoices' => $invoices,
            'url' => self::link($billing['billing_url'] ?? null),
        ];
    }

    /**
     * Один счёт.
     *
     * @param  array<mixed>  $item
     * @return Invoice
     */
    private static function invoice(array $item): array
    {
        $payments = [];

        foreach (is_array($item['payments'] ?? null) ? array_slice($item['payments'], 0, 20) : [] as $payment) {
            $payment = self::map($payment);
            $payments[] = ['paid_at' => self::date($payment['paid_at'] ?? null), 'amount' => self::integer($payment['amount'] ?? null), 'method' => self::text($payment['method_label'] ?? null, 100)];
        }

        return [
            'id' => self::text($item['id'] ?? null, 64),
            'act_available' => ($item['act_available'] ?? null) === true,
            'title' => self::text($item['title'] ?? null, 200),
            'period_from' => self::date($item['period_from'] ?? null),
            'period_to' => self::date($item['period_to'] ?? null),
            'amount' => self::integer($item['amount'] ?? null),
            'remaining' => self::integer($item['remaining_amount'] ?? null),
            'due_at' => self::date($item['due_at'] ?? null),
            'status_label' => self::text($item['status_label'] ?? null, 100),
            'tone' => self::tone($item['tone'] ?? null),
            'payments' => $payments,
            'url' => self::link($item['url'] ?? null),
        ];
    }

    /**
     * «Январь 2026» по строке «2026-01».
     */
    private static function monthLabel(string $month): string
    {
        $names = __('fastleo::softnetica.months', [], Fastleo::LOCALE);
        $name = is_array($names) ? ($names[(int) substr($month, 5, 2) - 1] ?? null) : null;

        return is_string($name) ? $name.' '.substr($month, 0, 4) : $month;
    }

    /**
     * Отчёты по месяцам (не больше 12), новые сверху.
     *
     * @return list<Report>
     */
    private static function reports(mixed $value): array
    {
        $reports = [];

        foreach (is_array($value) ? array_slice($value, 0, 12) : [] as $item) {
            $item = self::map($item);
            $month = $item['month'] ?? null;

            if (! is_string($month) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
                continue;
            }

            $reports[$month] = ['month' => $month, 'label' => self::monthLabel($month), 'current' => ($item['current'] ?? null) === true, 'url' => self::link($item['url'] ?? null)];
        }

        krsort($reports);

        return array_values($reports);
    }
}
