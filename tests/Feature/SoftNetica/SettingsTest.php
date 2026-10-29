<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\SoftNetica\Connection;
use Fastleo\SoftNetica\SoftNeticaSettings;
use Fastleo\SoftNetica\SyncState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
    Http::preventStrayRequests();
});

describe('the page', function (): void {
    it('is in the menu and explains who gets the token', function (): void {
        $this->get(route('fastleo.dashboard'))->assertSee(route('fastleo.settings.edit', 'softnetica'), false);

        $this->get(route('fastleo.settings.edit', 'softnetica'))
            ->assertOk()
            ->assertSee('Токен подключения')
            ->assertSee('Подключение доступно клиентам SoftNetica, токен выдаёт администратор SoftNetica')
            ->assertSee('data-softnetica-state="off"', false)
            ->assertDontSee('data-softnetica-check', false)
            ->assertDontSee('data-softnetica-disconnect', false);
    });

    it('saves the token encrypted and never prints it', function (): void {
        $this->put(route('fastleo.settings.update', 'softnetica'), ['token' => SOFTNETICA_TOKEN])->assertSessionHasNoErrors();

        $raw = (string) DB::table('fastleo_settings')->where('slug', 'softnetica')->value('value');

        expect($raw)->not->toContain('0123456789abcdef')
            ->and(Fastleo::setting('softnetica.token'))->toBe(SOFTNETICA_TOKEN)
            ->and(Connection::connected())->toBeTrue();

        $this->get(route('fastleo.settings.edit', 'softnetica'))
            ->assertOk()
            ->assertDontSee('0123456789abcdef')
            ->assertSee('data-softnetica-state="waiting"', false)
            ->assertSee('data-softnetica-check', false)
            ->assertSee('data-softnetica-disconnect', false);
    });

    it('keeps the saved token when the field is left empty and rejects a wrong format', function (): void {
        connectSoftNetica();

        $this->put(route('fastleo.settings.update', 'softnetica'), ['token' => ''])->assertSessionHasNoErrors();
        expect(Fastleo::setting('softnetica.token'))->toBe(SOFTNETICA_TOKEN);

        $this->put(route('fastleo.settings.update', 'softnetica'), ['token' => 'sn_abc'])->assertSessionHasErrors('token');
        $this->put(route('fastleo.settings.update', 'softnetica'), ['token' => 'xx_'.str_repeat('a', 48)])->assertSessionHasErrors('token');
        expect(Fastleo::setting('softnetica.token'))->toBe(SOFTNETICA_TOKEN);
    });

    it('shows the state: connected, stale, rejected', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => Http::sequence()->push(['accepted' => true, 'client' => ['domain' => 'site.ru']])->push([], 401)]);
        syncNow();

        $this->get(route('fastleo.settings.edit', 'softnetica'))->assertSee('data-softnetica-state="connected"', false)->assertSee(now()->timezone(Fastleo::timezone())->format('d.m.Y H:i'));

        $this->travel(31)->minutes();
        $this->get(route('fastleo.settings.edit', 'softnetica'))->assertSee('data-softnetica-state="stale"', false);

        syncNow();
        $this->get(route('fastleo.settings.edit', 'softnetica'))
            ->assertSee('data-softnetica-state="rejected"', false)
            ->assertSee('Подключение отклонено SoftNetica — проверьте токен или обратитесь в SoftNetica');
    });

    it('never leaks the token into the log while syncing', function (): void {
        connectSoftNetica();
        Log::spy();
        Http::fake([SOFTNETICA_URL => Http::response('boom', 500)]);

        $this->post(route('fastleo.softnetica.check'))->assertRedirect();

        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    });
});

it('shows the labels of the settings page in Russian whatever the locale of the application is', function (): void {
    app()->setLocale('en');

    $labels = collect(resolve(SoftNeticaSettings::class)->fields())->map(fn ($field): string => $field->getLabel());

    expect($labels->all())->toBe(['Токен подключения']);
});

describe('after a new token', function (): void {
    it('does not show the result of the old token as the result of the new one', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => Http::response('', 401)]);
        syncNow();

        connectSoftNetica(SOFTNETICA_OTHER_TOKEN);
        app()->forgetInstance(SyncState::class);

        expect(resolve(SyncState::class)->lastResult())->toBeNull()
            ->and(resolve(SyncState::class)->attemptedAt())->toBeNull();
    });
});

describe('request limits', function (): void {
    it('has its own limit for the integrations that does not share the profile one', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => acceptedResponse()]);

        for ($i = 0; $i < 5; $i++) {
            $this->put(route('fastleo.profile.update'), ['name' => 'Анна', 'email' => 'anna@example.com']);
        }

        $this->put(route('fastleo.profile.update'), ['name' => 'Анна', 'email' => 'anna@example.com'])->assertStatus(429);

        for ($i = 0; $i < 10; $i++) {
            $this->post(route('fastleo.softnetica.check'))->assertRedirect();
        }

        $this->post(route('fastleo.softnetica.check'))->assertStatus(429);
    });
});

describe('check the link', function (): void {
    it('reports a connection', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => acceptedResponse()]);

        $this->from(route('fastleo.settings.edit', 'softnetica'))->post(route('fastleo.softnetica.check'))
            ->assertRedirect(route('fastleo.settings.edit', 'softnetica'))
            ->assertSessionHas('fastleo.success', 'Подключено: SoftNetica приняла отчёт.');
    });

    it('reports a rejected token and no connection', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => Http::sequence()->push([], 401)->push('', 500)]);

        $this->post(route('fastleo.softnetica.check'))->assertSessionHas('fastleo.error', fn (string $message): bool => str_contains($message, 'отклонено SoftNetica'));
        $this->post(route('fastleo.softnetica.check'))->assertSessionHas('fastleo.error', fn (string $message): bool => str_contains($message, 'Нет связи'));
    });

    it('shows no connection instead of an error page when the answer cannot be saved', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => function () {
            Schema::drop('fastleo_settings');

            return acceptedResponse();
        }]);

        $this->post(route('fastleo.softnetica.check'))->assertRedirect()->assertSessionHas('fastleo.error', fn (string $message): bool => str_contains($message, 'Нет связи'));
    });

    it('asks for a token when there is none and sends nothing', function (): void {
        Http::fake();

        $this->post(route('fastleo.softnetica.check'))->assertSessionHas('fastleo.error', fn (string $message): bool => str_contains($message, 'Токен не введён'));
        Http::assertNothingSent();
    });

    it('requires a signed-in administrator and a CSRF token', function (): void {
        auth(Fastleo::GUARD)->logout();

        $this->post(route('fastleo.softnetica.check'))->assertRedirect(route('fastleo.login'));
        $this->post(route('fastleo.softnetica.disconnect'))->assertRedirect(route('fastleo.login'));
    });
});

describe('disconnect', function (): void {
    it('erases the token and the saved data', function (): void {
        connectSoftNetica();
        Http::fake([SOFTNETICA_URL => acceptedResponse()]);
        syncNow();

        $this->post(route('fastleo.softnetica.disconnect'))->assertSessionHas('fastleo.success', 'Сайт отключён от SoftNetica.');

        app()->forgetInstance(SyncState::class);
        expect(Fastleo::setting('softnetica.token'))->toBeNull()
            ->and(Connection::connected())->toBeFalse()
            ->and(DB::table('fastleo_settings')->where('slug', '_softnetica')->exists())->toBeFalse()
            ->and(storedClient())->toBeNull();

        $this->get(route('fastleo.settings.edit', 'softnetica'))->assertSee('data-softnetica-state="off"', false);
    });
});

describe('switched off in the config', function (): void {
    it('has no page, no actions and no connection', function (): void {
        connectSoftNetica();
        config(['fastleo.softnetica.enabled' => false]);
        Fastleo::resources()->flush();
        Http::fake();

        $this->get(route('fastleo.settings.edit', 'softnetica'))->assertNotFound();
        $this->post(route('fastleo.softnetica.check'))->assertNotFound();
        $this->post(route('fastleo.softnetica.disconnect'))->assertNotFound();
        $this->get(route('fastleo.dashboard'))->assertOk()->assertDontSee(route('fastleo.settings.edit', 'softnetica'), false);

        expect(Connection::connected())->toBeFalse();
        Http::assertNothingSent();
    });

    it('is switched off even when the sections are cached', function (): void {
        // Кеш — во временной папке: общий каркас Testbench читают параллельные процессы тестов.
        $sandbox = sys_get_temp_dir().'/fastleo-softnetica-'.getmypid().'-'.uniqid();
        File::ensureDirectoryExists($sandbox.'/cache');
        $this->app->useBootstrapPath($sandbox);

        try {
            $this->artisan('fastleo:cache')->assertSuccessful();
            expect(require Fastleo::resources()->cachePath())->not->toContain(SoftNeticaSettings::class);

            config(['fastleo.softnetica.enabled' => false]);
            Fastleo::resources()->flush();
            $this->get(route('fastleo.settings.edit', 'softnetica'))->assertNotFound();

            config(['fastleo.softnetica.enabled' => true]);
            Fastleo::resources()->flush();
            $this->get(route('fastleo.settings.edit', 'softnetica'))->assertOk();
        } finally {
            File::deleteDirectory($sandbox);
        }
    });
});
