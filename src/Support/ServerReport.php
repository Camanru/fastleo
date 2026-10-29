<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Composer\InstalledVersions;
use Fastleo\Fastleo;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Состояние сайта и сервера для виджета «Состояние сервера»: версии, настройки, лимиты, место на диске.
 *
 * Каждая строка — `key` (постоянное имя), `title`, `value`, `status` (`ok`, `warning`, `danger` или `null` для справки)
 * и `hint` с причиной и командой для исправления. Строки со статусом — проверки: их же берёт отчёт `HealthChecks`. Проверки «боевого сайта» (кеш конфигурации, отладка, почта, OPcache)
 * включаются только при `APP_ENV=production`, чтобы на локальной машине не пугать.
 *
 * @phpstan-type Row array{key: string, title: string, value: string, status: ?string, hint: ?string}
 */
final class ServerReport
{
    /** Меньше стольких свободных байт — критично (1 ГБ). */
    private const int DISK_DANGER_BYTES = 1_073_741_824;

    /** Самой старой задаче в очереди больше стольких секунд — очередь не разбирается. */
    private const int QUEUE_STALLED_SECONDS = 600;

    /**
     * Группы строк отчёта.
     *
     * @return list<array{title: string, rows: list<Row>}>
     */
    public static function sections(): array
    {
        return [
            ['title' => self::text('platform'), 'rows' => self::platform()],
            ['title' => self::text('checks'), 'rows' => self::checks()],
            ['title' => self::text('limits'), 'rows' => self::limits()],
        ];
    }

    /**
     * Сколько строк требуют внимания (`warning` и `danger`).
     *
     * @param  list<array{title: string, rows: list<Row>}>  $sections
     */
    public static function attention(array $sections): int
    {
        $count = 0;

        foreach ($sections as $section) {
            foreach ($section['rows'] as $row) {
                $count += in_array($row['status'], ['warning', 'danger'], true) ? 1 : 0;
            }
        }

        return $count;
    }

    /**
     * Версии fastleo, Laravel, PHP и базы данных (для отчёта в SoftNetica).
     *
     * @return array{fastleo: string, laravel: string, php: string, database: string}
     */
    public static function versions(): array
    {
        return [
            'fastleo' => self::packageVersion('camanru/fastleo'),
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'database' => self::database(),
        ];
    }

    /**
     * Версии и окружение.
     *
     * @return list<Row>
     */
    private static function platform(): array
    {
        $versions = self::versions();

        return [
            self::row('fastleo', $versions['fastleo']),
            self::row('laravel', $versions['laravel']),
            self::row('php', $versions['php']),
            self::row('database', $versions['database']),
            self::row('environment', config()->string('app.env', 'production')),
            self::row('time', now()->timezone(Fastleo::timezone())->format('d.m.Y H:i').' ('.Fastleo::timezone().')'),
        ];
    }

    /**
     * Проверки настройки и состояния сайта.
     *
     * @return list<Row>
     */
    private static function checks(): array
    {
        $production = app()->isProduction();
        $debug = config()->boolean('app.debug');
        $mailer = config()->string('mail.default', 'log');
        $cached = app()->configurationIsCached() && app()->routesAreCached();
        $writable = is_writable(storage_path()) && is_writable(app()->bootstrapPath('cache'));
        $disk = self::disk();

        return [
            self::row('debug', self::flag($debug), $debug && $production ? 'danger' : 'ok', $debug && $production ? self::text('debug_hint') : null),
            self::row('maintenance', self::flag(app()->isDownForMaintenance()), app()->isDownForMaintenance() ? 'danger' : 'ok', app()->isDownForMaintenance() ? self::text('maintenance_hint') : null),
            self::row('cache', self::flag($cached), $production && ! $cached ? 'warning' : 'ok', $production && ! $cached ? self::text('cache_hint') : null),
            self::queue(),
            self::scheduler(),
            self::row('mail', $mailer, $production && in_array($mailer, ['log', 'array'], true) ? 'warning' : 'ok', $production && in_array($mailer, ['log', 'array'], true) ? self::text('mail_hint') : null),
            self::row('storage_link', self::flag(file_exists(public_path('storage'))), file_exists(public_path('storage')) ? 'ok' : 'warning', file_exists(public_path('storage')) ? null : self::text('storage_link_hint')),
            self::row('writable', self::flag($writable), $writable ? 'ok' : 'danger', $writable ? null : self::text('writable_hint')),
            self::row('disk', $disk['value'], $disk['status'], $disk['hint']),
            self::databasePing(),
            ...self::failedJobs(),
        ];
    }

    /**
     * Очередь: драйвер `sync` — совет включить настоящую очередь; иначе сколько задач ждёт и не застряла ли самая старая.
     *
     * @return Row
     */
    private static function queue(): array
    {
        $driver = config()->string('queue.default', 'sync');

        if ($driver === 'sync') {
            return self::row('queue', $driver, 'warning', self::text('queue_hint'));
        }

        try {
            $connection = Queue::connection();
            $pending = (int) $connection->pendingSize();
            $oldest = $connection->creationTimeOfOldestPendingJob();
        } catch (Throwable) {
            return self::row('queue', $driver, 'warning', self::text('queue_unreachable_hint'));
        }

        $age = is_numeric($oldest) ? max(0, now()->getTimestamp() - (int) $oldest) : 0;
        $value = $driver.', '.self::text('queue_waiting').': '.$pending;

        if ($age > self::QUEUE_STALLED_SECONDS) {
            return self::row('queue', $value, 'warning', str_replace(':minutes', (string) intdiv($age, 60), self::text('queue_stalled_hint')));
        }

        return self::row('queue', $value, 'ok');
    }

    /**
     * Планировщик: когда в последний раз запускался `schedule:run`. Предупреждение — только на боевом сайте.
     *
     * @return Row
     */
    private static function scheduler(): array
    {
        $last = SchedulerPulse::lastRun();
        $value = $last === null
            ? self::text('scheduler_never')
            : str_replace(':minutes', (string) intdiv(max(0, now()->getTimestamp() - $last), 60), self::text('scheduler_ago'));

        if (! app()->isProduction()) {
            return self::row('scheduler', $value);
        }

        $stale = SchedulerPulse::isStale();

        return self::row('scheduler', $value, $stale ? 'warning' : 'ok', $stale ? self::text('scheduler_hint') : null);
    }

    /**
     * Ответ базы данных на простой запрос.
     *
     * @return Row
     */
    private static function databasePing(): array
    {
        try {
            DB::connection()->select('select 1');
        } catch (Throwable) {
            return self::row('database_ping', self::text('database_down'), 'danger', self::text('database_hint'));
        }

        return self::row('database_ping', self::text('database_up'), 'ok');
    }

    /**
     * Упавшие задачи очереди (если журнал упавших задач умеет считать и таблица есть).
     *
     * @return list<Row>
     */
    private static function failedJobs(): array
    {
        $count = self::countFailed(resolve('queue.failer'));

        if ($count === null) {
            return [];
        }

        return [self::row('failed_jobs', (string) $count, $count > 0 ? 'warning' : 'ok', $count > 0 ? self::text('failed_jobs_hint') : null)];
    }

    /**
     * Сколько задач в журнале упавших; null — журнал не умеет считать или недоступен.
     */
    private static function countFailed(object $failer): ?int
    {
        if (! $failer instanceof CountableFailedJobProvider) {
            return null;
        }

        try {
            return $failer->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Лимиты PHP.
     *
     * @return list<Row>
     */
    private static function limits(): array
    {
        $upload = self::bytes((string) ini_get('upload_max_filesize'));
        $post = self::bytes((string) ini_get('post_max_size'));
        $postTooSmall = $post > 0 && $upload > $post;
        $opcache = function_exists('opcache_get_status') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);

        return [
            self::row('memory_limit', ini_get('memory_limit')),
            self::row('max_execution_time', ini_get('max_execution_time').' '.self::text('seconds')),
            self::row('upload_max_filesize', (string) ini_get('upload_max_filesize')),
            self::row('post_max_size', (string) ini_get('post_max_size'), $postTooSmall ? 'warning' : 'ok', $postTooSmall ? self::text('post_hint') : null),
            self::row('opcache', self::flag($opcache), app()->isProduction() && ! $opcache ? 'warning' : 'ok', app()->isProduction() && ! $opcache ? self::text('opcache_hint') : null),
        ];
    }

    /**
     * Свободное место на диске с сайтом.
     *
     * @return array{value: string, status: string, hint: ?string}
     */
    private static function disk(): array
    {
        $free = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());

        if ($free === false || $total === false || $total <= 0) {
            return ['value' => '—', 'status' => 'ok', 'hint' => null];
        }

        $share = $free / $total;
        $status = $free < self::DISK_DANGER_BYTES || $share < 0.05 ? 'danger' : ($share < 0.15 ? 'warning' : 'ok');

        return [
            'value' => CacheCleaner::format((int) $free).' '.self::text('of').' '.CacheCleaner::format((int) $total),
            'status' => $status,
            'hint' => $status === 'ok' ? null : self::text('disk_hint'),
        ];
    }

    /**
     * Версия пакета Composer или тире.
     */
    private static function packageVersion(string $package): string
    {
        try {
            return InstalledVersions::getPrettyVersion($package) ?? '—';
        } catch (Throwable) {
            return '—';
        }
    }

    /**
     * Драйвер и версия базы данных.
     */
    private static function database(): string
    {
        try {
            $connection = DB::connection();
            $version = $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);

            return $connection->getDriverName().' '.(is_scalar($version) ? $version : '');
        } catch (Throwable) {
            return '—';
        }
    }

    /**
     * Размер из php.ini («8M», «2G») в байтах.
     */
    private static function bytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * «Включено» / «Выключено».
     */
    private static function flag(bool $on): string
    {
        return self::text($on ? 'on' : 'off');
    }

    /**
     * Строка отчёта с подписью из переводов.
     *
     * @return Row
     */
    private static function row(string $key, string $value, ?string $status = null, ?string $hint = null): array
    {
        return ['key' => $key, 'title' => self::text('row_'.$key), 'value' => $value, 'status' => $status, 'hint' => $hint];
    }

    /**
     * Текст из `lang/ru/dashboard.php`, раздел `server`.
     */
    private static function text(string $key): string
    {
        return __('fastleo::dashboard.server.'.$key, [], Fastleo::LOCALE);
    }
}
