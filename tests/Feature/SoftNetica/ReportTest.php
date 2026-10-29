<?php

declare(strict_types=1);

use Fastleo\Auth\FailedLogins;
use Fastleo\Auth\LoginRecord;
use Fastleo\Fastleo;
use Fastleo\Health\HealthChecks;
use Fastleo\Health\HealthResult;
use Fastleo\Health\HealthText;
use Fastleo\SoftNetica\SyncReport;
use Fastleo\Support\ServerReport;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\User;

/**
 * Проверки отчёта по именам.
 *
 * @return array<string, array<string, string>>
 */
function collectedChecks(): array
{
    $checks = [];

    foreach (resolve(HealthChecks::class)->collect() as $check) {
        $checks[$check['name']] = $check;
    }

    return $checks;
}

describe('built-in checks', function (): void {
    it('uses only reserved names for the rows of the server report', function (): void {
        foreach (ServerReport::sections() as $section) {
            foreach ($section['rows'] as $row) {
                if ($row['status'] !== null) {
                    expect(HealthChecks::RESERVED)->toContain($row['key']);
                }
            }
        }
    });

    it('maps the server report to checks with ok, warning and failed statuses', function (): void {
        config(['app.debug' => true]);
        $this->app['env'] = 'production';

        $checks = collectedChecks();

        expect($checks['debug']['status'])->toBe('failed')
            ->and($checks['debug']['message'])->toContain('APP_DEBUG=false')
            ->and($checks['debug']['label'])->toBe('Режим отладки (APP_DEBUG)')
            ->and($checks['database_ping'])->toMatchArray(['status' => 'ok', 'summary' => 'Отвечает'])
            ->and($checks)->not->toHaveKey('fastleo')
            ->and($checks['disk'])->toHaveKey('summary');
    });

    it('warns when the sync queue is used', function (): void {
        config(['queue.default' => 'sync']);

        expect(collectedChecks()['queue'])->toMatchArray(['status' => 'warning']);
    });

    it('reports the queue size and a stalled queue on the database driver', function (): void {
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinute()->getTimestamp(), 'created_at' => now()->subMinute()->getTimestamp()]);

        expect(collectedChecks()['queue'])->toMatchArray(['status' => 'ok', 'summary' => 'database, ждут задач: 1']);

        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(20)->getTimestamp(), 'created_at' => now()->subMinutes(20)->getTimestamp()]);

        $queue = collectedChecks()['queue'];
        expect($queue['status'])->toBe('warning')->and($queue['message'])->toContain('20 мин');
    });

    it('warns when the queue cannot be read', function (): void {
        config(['queue.default' => 'database']);
        DB::statement('drop table jobs');

        expect(collectedChecks()['queue']['status'])->toBe('warning');
    });

    it('counts failed jobs', function (): void {
        config(['queue.failed.database' => DB::getDefaultConnection()]);

        expect(collectedChecks()['failed_jobs'])->toMatchArray(['status' => 'ok', 'summary' => '0']);

        DB::table('failed_jobs')->insert(['uuid' => 'a', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

        expect(collectedChecks()['failed_jobs'])->toMatchArray(['status' => 'warning', 'summary' => '1']);
    });

    it('skips the failed jobs row when the table is missing', function (): void {
        config(['queue.failed.database' => DB::getDefaultConnection(), 'queue.failed.table' => 'missing_failed_jobs']);

        expect(collectedChecks())->not->toHaveKey('failed_jobs');
    });

    it('reports a database that does not answer', function (): void {
        $original = config('database.default');
        config(['database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/db.sqlite'], 'database.default' => 'broken']);

        try {
            $check = collectedChecks()['database_ping'];
        } finally {
            config(['database.default' => $original]);
        }

        expect($check)->toMatchArray(['status' => 'failed', 'summary' => 'Не отвечает']);
    });
});

describe('site checks', function (): void {
    it('registers a check through Fastleo::healthCheck and reports its result', function (): void {
        Fastleo::healthCheck('leads', 'Заявки', fn (): HealthResult => HealthResult::warning('Заявок нет уже 3 дня'));
        Fastleo::healthCheck('crm', 'CRM', fn (): HealthResult => HealthResult::ok());
        Fastleo::healthCheck('mail.out', 'Почта', fn (): HealthResult => HealthResult::failed('не отправляется'));

        $checks = collectedChecks();

        expect($checks['leads'])->toBe(['name' => 'leads', 'label' => 'Заявки', 'status' => 'warning', 'summary' => 'Заявок нет уже 3 дня'])
            ->and($checks['crm'])->toBe(['name' => 'crm', 'label' => 'CRM', 'status' => 'ok'])
            ->and($checks['mail.out']['status'])->toBe('failed');
    });

    it('turns an exception into a failed check without breaking the report', function (): void {
        Fastleo::healthCheck('boom', 'Сломанная', fn (): HealthResult => throw new RuntimeException('секрет пароль'));
        Fastleo::healthCheck('fine', 'Рабочая', fn (): HealthResult => HealthResult::ok());

        $checks = collectedChecks();

        expect($checks['boom'])->toMatchArray(['status' => 'failed', 'summary' => 'проверка упала'])
            ->and(json_encode($checks))->not->toContain('пароль')
            ->and($checks['fine']['status'])->toBe('ok');
    });

    it('fails a check that returns something else', function (): void {
        Fastleo::healthCheck('wrong', 'Не то', fn (): bool => true);

        expect(collectedChecks()['wrong'])->toMatchArray(['status' => 'failed', 'summary' => 'проверка вернула не HealthResult']);
    });

    it('cleans links, emails, addresses and paths out of the texts', function (): void {
        Fastleo::healthCheck('dirty', 'Проверка https://evil.example/x', fn (): HealthResult => HealthResult::failed("Ошибка\x07 у ivan@example.com с 192.168.1.10, файл ".base_path('storage/app/x.php').' и /var/www/site/.env, см. http://example.com/log'));

        $check = collectedChecks()['dirty'];

        expect($check['label'])->toBe('Проверка [ссылка]')
            ->and($check['summary'])->not->toContain('ivan@')
            ->and($check['summary'])->not->toContain('192.168')
            ->and($check['summary'])->not->toContain('/var/www')
            ->and($check['summary'])->not->toContain('http')
            ->and($check['summary'])->not->toContain("\x07")
            ->and($check['summary'])->toContain('[скрыто]')->toContain('[ссылка]')->toContain('[путь]');
    });

    it('limits the length of the label, summary and message', function (): void {
        Fastleo::healthCheck('long', str_repeat('Я', 300), fn (): HealthResult => HealthResult::warning(str_repeat('слово ', 200)));

        $check = collectedChecks()['long'];

        expect(mb_strlen($check['label']))->toBeLessThanOrEqual(100)
            ->and(mb_strlen($check['summary']))->toBeLessThanOrEqual(200)
            ->and(mb_strlen($check['message']))->toBeLessThanOrEqual(500);
    });

    it('rejects invalid, taken and empty names', function (string $name, string $label): void {
        expect(fn () => Fastleo::healthCheck($name, $label, fn (): HealthResult => HealthResult::ok()))->toThrow(LogicException::class);
    })->with([
        'space in name' => ['my check', 'X'],
        'starts with dash' => ['-x', 'X'],
        'too long' => [str_repeat('a', 65), 'X'],
        'reserved' => ['queue', 'X'],
        'reserved in upper case' => ['DISK', 'X'],
        'empty label' => ['ok-name', '  '],
    ]);

    it('rejects a repeated name in any case and too many checks', function (): void {
        Fastleo::healthCheck('Leads', 'Заявки', fn (): HealthResult => HealthResult::ok());

        expect(fn () => Fastleo::healthCheck('leads', 'Ещё', fn (): HealthResult => HealthResult::ok()))->toThrow(LogicException::class);

        $room = HealthChecks::MAX_CHECKS - count(HealthChecks::RESERVED) - 1;

        for ($i = 0; $i < $room; $i++) {
            Fastleo::healthCheck('extra-'.$i, 'Проверка', fn (): HealthResult => HealthResult::ok());
        }

        expect(fn () => Fastleo::healthCheck('one-more', 'Лишняя', fn (): HealthResult => HealthResult::ok()))->toThrow(LogicException::class)
            ->and(count(resolve(HealthChecks::class)->collect()))->toBeLessThanOrEqual(HealthChecks::MAX_CHECKS);
    });

    it('keeps names unique and valid for the panel', function (): void {
        Fastleo::healthCheck('a.b:c_d-1', 'Имя', fn (): HealthResult => HealthResult::ok());

        $names = array_column(resolve(HealthChecks::class)->collect(), 'name');

        expect($names)->toBe(array_values(array_unique($names)));

        foreach ($names as $name) {
            expect($name)->toMatch('/^[a-z0-9][a-z0-9_.:-]*$/i');
        }
    });
});

describe('text cleaning', function (): void {
    it('removes secrets that look like keys', function (): void {
        expect(HealthText::clean('ключ sn_0123456789abcdef0123456789abcdef0123456789abcdef в тексте', 200))->toBe('ключ [скрыто] в тексте');
    });

    it('hides shortened IPv6 addresses but not code that looks alike', function (string $text, string $expected): void {
        expect(HealthText::clean($text, 200))->toBe($expected);
    })->with([
        'link-local' => ['Нет доступа с fe80::1 к базе', 'Нет доступа с [скрыто] к базе'],
        'documentation prefix' => ['Клиент 2001:db8::1 отклонён', 'Клиент [скрыто] отклонён'],
        'loopback' => ['Слушает ::1 порт', 'Слушает [скрыто] порт'],
        'long tail' => ['fe80::1ff:fe23:4567:890a недоступен', '[скрыто] недоступен'],
        'class constant' => ['Не найден App\\Models\\User::class', 'Не найден App\\Models\\User::class'],
        'method call' => ['Ошибка в Foo::bar()', 'Ошибка в Foo::bar()'],
    ]);

    it('keeps ordinary text as is', function (): void {
        expect(HealthText::clean('Очередь не разбирается 10 мин, 120 задач/мин', 200))->toBe('Очередь не разбирается 10 мин, 120 задач/мин');
    });
});

describe('failed logins', function (): void {
    it('counts attempts of the last hour only', function (): void {
        FailedLogins::hit();
        FailedLogins::hit();
        expect(FailedLogins::lastHour())->toBe(2);

        $this->travel(30)->minutes();
        FailedLogins::hit();
        expect(FailedLogins::lastHour())->toBe(3);

        $this->travel(45)->minutes();
        expect(FailedLogins::lastHour())->toBe(1);
    });

    it('counts a wrong password and a wrong second-step code', function (): void {
        $admin = admin(['email' => 'boss@example.com']);

        login(['email' => 'boss@example.com', 'password' => 'wrong']);
        login(['email' => 'nobody@example.com', 'password' => 'wrong']);
        expect(FailedLogins::lastHour())->toBe(2);

        withTwoFactor($admin);
        login(['email' => 'boss@example.com', 'password' => 'password']);
        $this->post(route('fastleo.two-factor.challenge.store'), ['code' => '000000']);

        expect(FailedLogins::lastHour())->toBe(3);
    });

    it('does not count a successful login', function (): void {
        admin(['email' => 'boss@example.com']);

        login(['email' => 'boss@example.com', 'password' => 'password']);

        expect(FailedLogins::lastHour())->toBe(0);
    });
});

describe('report body', function (): void {
    it('has exactly versions, checks and admin_security', function (): void {
        $report = resolve(SyncReport::class)->build();

        expect(array_keys($report))->toBe(['versions', 'checks', 'admin_security'])
            ->and(array_keys($report['versions']))->toBe(['fastleo', 'laravel', 'php', 'database'])
            ->and(count($report['checks']))->toBeLessThanOrEqual(30)
            ->and(array_keys($report['admin_security']))->toBe(['admins', 'admins_without_two_factor', 'failed_logins_last_hour', 'new_address_logins_last_hour']);
    });

    it('counts administrators, those without two-factor protection and failed logins', function (): void {
        $protected = admin(['email' => 'a@example.com']);
        admin(['email' => 'b@example.com']);
        User::factory()->create(['email' => 'customer@example.com']);
        withTwoFactor($protected);
        FailedLogins::hit();

        $security = resolve(SyncReport::class)->build()['admin_security'];

        expect($security)->toBe(['admins' => 2, 'admins_without_two_factor' => 1, 'failed_logins_last_hour' => 1, 'new_address_logins_last_hour' => 0]);
    });

    it('counts a login from a new address only for an administrator who logged in before', function (): void {
        $old = admin(['email' => 'old@example.com']);
        $first = admin(['email' => 'first@example.com']);
        $key = fn (User $user): string => (string) $user->getKey();
        $record = fn (User $user, string $ip, $at) => LoginRecord::query()->create(['user_id' => $key($user), 'fingerprint' => sha1($ip.$at), 'ip' => $ip, 'user_agent' => 'x', 'created_at' => $at, 'last_seen_at' => $at]);

        $record($old, '10.0.0.1', now()->subDays(3));
        $record($old, '10.0.0.1', now()->subMinutes(10));
        $record($old, '10.0.0.2', now()->subMinutes(5));
        $record($old, '10.0.0.2', now()->subMinutes(4));
        $record($first, '10.0.0.9', now()->subMinutes(3));

        expect(resolve(SyncReport::class)->build()['admin_security']['new_address_logins_last_hour'])->toBe(1)
            ->and(json_encode(resolve(SyncReport::class)->build()))->not->toContain('10.0.0')->not->toContain('example.com');
    });
});
