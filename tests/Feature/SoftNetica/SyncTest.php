<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\SoftNetica\Connection;
use Fastleo\SoftNetica\Synchronizer;
use Fastleo\SoftNetica\SyncResult;
use Fastleo\SoftNetica\SyncState;
use Fastleo\Support\SettingsStore;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

describe('without a token', function (): void {
    it('sends nothing: neither the synchronizer, nor the command, nor the pages', function (): void {
        Http::fake();
        $this->actingAs(admin(), Fastleo::GUARD);

        expect(syncNow())->toBe(SyncResult::NotConfigured)
            ->and(Connection::connected())->toBeFalse();

        $this->artisan('fastleo:softnetica-sync')->expectsOutputToContain('не подключён')->assertSuccessful();
        $this->get(route('fastleo.dashboard'))->assertOk();
        $this->get(route('fastleo.settings.edit', 'softnetica'))->assertOk();

        Http::assertNothingSent();
    });

    it('does not run the scheduled command', function (): void {
        $event = collect(resolve(Schedule::class)->events())->first(fn (Event $event): bool => str_contains((string) $event->command, 'fastleo:softnetica-sync'));

        expect($event)->toBeInstanceOf(Event::class)
            ->and($event->filtersPass($this->app))->toBeFalse();

        connectSoftNetica();
        app()->forgetInstance(SyncState::class);

        expect($event->filtersPass($this->app))->toBeTrue()
            ->and($event->expression)->toBe('*/5 * * * *')
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->onOneServer)->toBeTrue()
            ->and($event->runInBackground)->toBeTrue();
    });

    it('ignores a token of a wrong format', function (): void {
        DB::table('fastleo_settings')->insert(['slug' => 'softnetica', 'value' => json_encode(['token' => 'sn_short']), 'created_at' => now(), 'updated_at' => now()]);

        expect(Connection::token())->toBe('');
    });
});

describe('a failure while building the report or saving the answer', function (): void {
    it('sends the report without the part that failed and writes the failure to the log', function (): void {
        connectSoftNetica();
        Log::spy();
        Http::fake([SOFTNETICA_URL => acceptedResponse()]);
        Fastleo::resolveAdminsUsing(fn () => throw new RuntimeException('нет таблицы'));

        try {
            $result = syncNow();
        } finally {
            Fastleo::resolveAdminsUsing(null);
        }

        expect($result)->toBe(SyncResult::Connected);
        Http::assertSent(fn (HttpRequest $request): bool => ! array_key_exists('admin_security', $request->data()) && array_key_exists('versions', $request->data()) && is_array($request['checks']));
        Log::shouldHaveReceived('error')->once();
    });

    it('still reports a database that does not answer and logs that the answer could not be saved', function (): void {
        connectSoftNetica();
        Fastleo::setting('softnetica.token');
        Log::spy();
        Http::fake([SOFTNETICA_URL => acceptedResponse()]);
        $original = config('database.default');
        config(['database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/db.sqlite'], 'database.default' => 'broken']);

        try {
            $result = syncNow();
        } finally {
            config(['database.default' => $original]);
        }

        expect($result)->toBe(SyncResult::Offline);
        Http::assertSent(fn (HttpRequest $request): bool => ! array_key_exists('admin_security', $request->data())
            && collect($request['checks'])->contains(fn (array $check): bool => $check['name'] === 'database_ping' && $check['status'] === 'failed'));
        Log::shouldHaveReceived('error');
    });

    it('marks every token parameter as sensitive so that it does not reach an error tracker', function (): void {
        foreach ([Synchronizer::class, SyncState::class] as $class) {
            foreach (new ReflectionClass($class)->getMethods() as $method) {
                foreach ($method->getParameters() as $parameter) {
                    if ($parameter->getName() === 'token') {
                        expect($parameter->getAttributes(SensitiveParameter::class))->not->toBeEmpty("{$class}::{$method->getName()}");
                    }
                }
            }
        }
    });
});

describe('a broken database', function (): void {
    it('means not connected and does not break the check', function (): void {
        connectSoftNetica();
        Schema::drop('fastleo_settings');
        app()->forgetInstance(SyncState::class);

        expect(Connection::connected())->toBeFalse();
    });

    it('treats a big error answer as no connection without a report in the log', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => fn () => throw new RequestException(new HttpResponse(new Psr7Response(500, [], 'x')))]);
        Exceptions::fake();

        expect(syncNow())->toBe(SyncResult::Offline);
        Exceptions::assertNothingReported();
    });

    it('does not throw when the answer cannot be saved', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => function () {
            Schema::drop('fastleo_settings');

            return acceptedResponse();
        }]);

        expect(syncNow())->toBe(SyncResult::Offline);
    });

    it('ignores a broken date in the saved state', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => acceptedResponse()]);
        syncNow();
        $row = json_decode((string) DB::table('fastleo_settings')->where('slug', '_softnetica')->value('value'), true);
        $row['synced_at'] = 'не дата';
        DB::table('fastleo_settings')->where('slug', '_softnetica')->update(['value' => json_encode($row)]);
        app()->forgetInstance(SyncState::class);

        expect(resolve(SyncState::class)->syncedAt())->toBeNull()->and(resolve(SyncState::class)->isStale())->toBeTrue();
    });
});

describe('the address of the panel', function (): void {
    it('accepts only https, and http only on a local site', function (string $url, string $env, ?string $expected): void {
        config(['fastleo.softnetica.url' => $url]);
        $this->app['env'] = $env;

        expect(Connection::endpoint())->toBe($expected);
    })->with([
        'https' => ['https://panel.softnetica.com', 'production', 'https://panel.softnetica.com'],
        'trailing slash' => ['https://panel.softnetica.com/', 'production', 'https://panel.softnetica.com'],
        'upper case host' => ['https://Panel.Softnetica.com', 'production', 'https://panel.softnetica.com'],
        'http in production' => ['http://panel.softnetica.com', 'production', null],
        'http on a local site' => ['http://panel.test', 'local', 'http://panel.test'],
        'http with a port on a local site' => ['http://panel.test:8080', 'local', 'http://panel.test:8080'],
        'login in the address' => ['https://user:pass@panel.softnetica.com', 'production', null],
        'path' => ['https://panel.softnetica.com/api', 'production', null],
        'query' => ['https://panel.softnetica.com?x=1', 'production', null],
        'other scheme' => ['ftp://panel.softnetica.com', 'local', null],
        'not an address' => ['панель', 'production', null],
        'empty' => ['', 'production', null],
    ]);

    it('does not send anything to a wrong address', function (): void {
        connectSoftNetica();
        config(['fastleo.softnetica.url' => 'http://panel.softnetica.com']);
        Http::fake();

        expect(syncNow())->toBe(SyncResult::BadUrl);
        Http::assertNothingSent();
    });

    it('lists the cabinet hosts in lower case', function (): void {
        config(['fastleo.softnetica.cabinet_hosts' => ['My.Softnetica.com', '', 5, 'other.test']]);

        expect(Connection::cabinetHosts())->toBe(['my.softnetica.com', 'other.test']);

        config(['fastleo.softnetica.cabinet_hosts' => 'my.softnetica.com']);
        expect(Connection::cabinetHosts())->toBe([]);
    });
});

describe('the request', function (): void {
    it('goes to the panel with the token, the headers and only the allowed fields', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => acceptedResponse()]);

        expect(syncNow())->toBe(SyncResult::Connected);

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && $request->url() === SOFTNETICA_URL
            && $request->hasHeader('Authorization', 'Bearer '.SOFTNETICA_TOKEN)
            && $request->hasHeader('Accept', 'application/json')
            && str_contains($request->header('Content-Type')[0], 'application/json')
            && str_starts_with($request->header('User-Agent')[0], 'Fastleo/')
            && array_keys($request->data()) === ['versions', 'checks', 'admin_security']
            && is_array($request['checks'])
            && ! str_contains(json_encode($request->data()), SOFTNETICA_TOKEN));
    });

    it('follows no redirects', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => Http::response('', 302, ['Location' => 'https://evil.example/'])]);

        expect(syncNow())->toBe(SyncResult::Offline);
        Http::assertSentCount(1);
    });

    it('goes to a local panel over http on a local site', function (): void {
        connectSoftNetica();
        $this->app['env'] = 'local';
        config(['fastleo.softnetica.url' => 'http://panel.test']);
        Http::fake(['http://panel.test/api/fastleo/sync' => acceptedResponse()]);

        expect(syncNow())->toBe(SyncResult::Connected);
    });
});

describe('the answer of the panel', function (): void {
    it('saves the client data on 200', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => acceptedResponse(['domain' => 'site.ru', 'uptime_30d' => 99.9])]);

        syncNow();

        expect(storedClient())->toBe(['domain' => 'site.ru', 'uptime_30d' => 99.9])
            ->and(resolve(SyncState::class)->syncedAt()?->diffInSeconds(now(), true))->toBeLessThan(5)
            ->and(resolve(SyncState::class)->isStale())->toBeFalse()
            ->and(resolve(SyncState::class)->lastResult())->toBe(SyncResult::Connected);
    });

    it('stores neither the token nor anything else outside the service row', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => acceptedResponse()]);
        syncNow();

        $row = (string) DB::table('fastleo_settings')->where('slug', '_softnetica')->value('value');

        expect($row)->not->toContain(SOFTNETICA_TOKEN)->and(Fastleo::setting('_softnetica.client'))->toBeNull();
    });

    it('keeps the service row out of the settings cache', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => acceptedResponse()]);
        syncNow();
        resolve(SettingsStore::class)->flush();

        expect(resolve(SettingsStore::class)->values('_softnetica'))->toBe([]);
    });

    it('treats 200 without the agreed body as no connection and keeps the data', function (mixed $body): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => Http::sequence()->push(['accepted' => true, 'client' => ['domain' => 'site.ru']])->push($body)]);
        syncNow();

        expect(syncNow())->toBe(SyncResult::Offline)
            ->and(storedClient())->toBe(['domain' => 'site.ru']);
    })->with([
        'not json' => ['<html>'],
        'not accepted' => [['accepted' => false, 'client' => []]],
        'no client' => [['accepted' => true]],
        'client is not an object' => [['accepted' => true, 'client' => 'x']],
    ]);

    it('rejects a very large answer', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => Http::response(['accepted' => true, 'client' => ['x' => str_repeat('a', 600_000)]])]);

        expect(syncNow())->toBe(SyncResult::Offline)->and(storedClient())->toBeNull();
    });

    it('erases the data on 401 and stops sending until the token changes or the link is checked by hand', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => Http::sequence()
            ->push(['accepted' => true, 'client' => ['domain' => 'site.ru']])
            ->push(['message' => 'no'], 401)
            ->push(['accepted' => true, 'client' => ['domain' => 'again.ru']])
            ->push(['message' => 'no'], 401)
            ->push(['accepted' => true, 'client' => ['domain' => 'new.ru']])]);

        syncNow();
        expect(syncNow())->toBe(SyncResult::Rejected)
            ->and(storedClient())->toBeNull()
            ->and(Connection::connected())->toBeFalse();

        $this->artisan('fastleo:softnetica-sync')->assertSuccessful();
        Http::assertSentCount(2);

        expect(syncNow())->toBe(SyncResult::Connected)
            ->and(Connection::connected())->toBeTrue()
            ->and(storedClient())->toBe(['domain' => 'again.ru']);

        expect(syncNow())->toBe(SyncResult::Rejected);
        Http::assertSentCount(4);

        connectSoftNetica(SOFTNETICA_OTHER_TOKEN);
        app()->forgetInstance(SyncState::class);
        expect(Connection::connected())->toBeTrue();

        $this->artisan('fastleo:softnetica-sync')->assertSuccessful();
        Http::assertSentCount(5);
        expect(storedClient())->toBe(['domain' => 'new.ru']);
    });

    it('does not run the scheduled command after 401', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => Http::response([], 401)]);
        syncNow();

        $event = collect(resolve(Schedule::class)->events())->first(fn (Event $event): bool => str_contains((string) $event->command, 'fastleo:softnetica-sync'));
        app()->forgetInstance(SyncState::class);

        expect($event->filtersPass($this->app))->toBeFalse();
    });

    it('keeps the data on 429, 422, 5xx, a timeout and a network error', function (int $status, SyncResult $expected): void {
        connectSoftNetica();
        $responses = Http::sequence()->push(['accepted' => true, 'client' => ['domain' => 'site.ru']]);
        $status === 0 ? $responses->pushFailedConnection() : $responses->push(['errors' => ['checks' => ['bad']]], $status);
        Http::fake([SOFTNETICA_URL => $responses]);
        syncNow();

        expect(syncNow())->toBe($expected)
            ->and(storedClient())->toBe(['domain' => 'site.ru'])
            ->and(Connection::connected())->toBeTrue()
            ->and(resolve(SyncState::class)->lastResult())->toBe($expected);
    })->with([
        '429' => [429, SyncResult::Throttled],
        '422' => [422, SyncResult::Invalid],
        '500' => [500, SyncResult::Offline],
        '503' => [503, SyncResult::Offline],
        'no connection' => [0, SyncResult::Offline],
    ]);

    it('aborts the download of an answer that is too big', function (): void {
        expect(fn () => Synchronizer::limitDownload(524_289, 0))->toThrow(RuntimeException::class)
            ->and(fn () => Synchronizer::limitDownload(0, 524_289))->toThrow(RuntimeException::class);

        Synchronizer::limitDownload(524_288, 524_288);
        Synchronizer::limitDownload(0, 0);
    });

    it('does not bring the data back after the token was erased while the request was on the way', function (): void {
        connectSoftNetica();
        Http::fake(function () {
            // Пока идёт запрос, администратор нажал «Отключить».
            resolve(SettingsStore::class)->save(Fastleo::resources()->findSettings('softnetica'), ['token' => '']);

            return acceptedResponse();
        });

        syncNow();

        expect(DB::table('fastleo_settings')->where('slug', SyncState::SLUG)->exists())->toBeFalse();
    });

    it('does not record a result for the old token after a new one was entered', function (): void {
        connectSoftNetica();
        Http::fake(function () {
            connectSoftNetica(SOFTNETICA_OTHER_TOKEN);

            return Http::response('', 401);
        });

        syncNow();

        app()->forgetInstance(SyncState::class);
        expect(resolve(SyncState::class)->isRejected(SOFTNETICA_OTHER_TOKEN))->toBeFalse()
            ->and(Connection::connected())->toBeTrue();
    });

    it('does not throw when the connection fails', function (): void {
        connectSoftNetica();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        expect(syncNow())->toBe(SyncResult::Offline);
    });

    it('scrubs the token from the log of 422 even at the edge of the cut', function (): void {
        connectSoftNetica();
        Log::spy();
        Http::fake([SOFTNETICA_URL => Http::response(['errors' => ['checks' => [str_repeat('x', 985).SOFTNETICA_TOKEN]]], 422)]);

        syncNow();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => ! str_contains($message, 'sn_0') && ! str_contains($message, '0123456789abcdef') && str_contains($message, '***'));
    });

    it('logs 422 without the token', function (): void {
        connectSoftNetica();
        Log::spy();
        Http::fake([SOFTNETICA_URL => Http::response(['errors' => ['checks.0.name' => ['bad name '.SOFTNETICA_TOKEN]]], 422)]);

        syncNow();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'checks.0.name') && ! str_contains($message, SOFTNETICA_TOKEN));
    });

    it('ignores data of a previous token', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => acceptedResponse()]);
        syncNow();

        connectSoftNetica(SOFTNETICA_OTHER_TOKEN);

        expect(storedClient())->toBeNull()->and(resolve(SyncState::class)->isStale())->toBeTrue();
    });

    it('marks old data as stale', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => acceptedResponse()]);
        syncNow();

        $this->travel(31)->minutes();
        app()->forgetInstance(SyncState::class);
        expect(resolve(SyncState::class)->isStale())->toBeTrue();
    });
});

describe('the command', function (): void {
    it('syncs and reports the result', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => acceptedResponse()]);

        $this->artisan('fastleo:softnetica-sync')->expectsOutputToContain('Подключено')->assertSuccessful();
    });

    it('fails when the panel is not reachable', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => Http::response('', 500)]);

        $this->artisan('fastleo:softnetica-sync')->expectsOutputToContain('Нет связи')->assertFailed();
    });

    it('does not fail on a rate limit', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => Http::response('', 429)]);

        $this->artisan('fastleo:softnetica-sync')->assertSuccessful();
    });

    it('does nothing when the function is switched off', function (): void {
        connectSoftNetica();
        config(['fastleo.softnetica.enabled' => false]);
        Http::fake();

        $this->artisan('fastleo:softnetica-sync')->assertSuccessful();
        Http::assertNothingSent();
    });
});
