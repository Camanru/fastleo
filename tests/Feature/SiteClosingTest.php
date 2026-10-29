<?php

declare(strict_types=1);

use Fastleo\Closing\SiteClosing;
use Fastleo\Fastleo;
use Fastleo\Support\SettingsStore;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Workbench\App\Models\User;

/**
 * Включить «Закрытие сайта»: проверка подключается провайдером при старте только по настройке, поэтому в тесте — вручную.
 */
function enableClosing(bool $closed = true, ?string $message = null): void
{
    config(['fastleo.closing.enabled' => true]);
    SiteClosing::register();
    Route::middleware('web')->get('/closing-page', fn (): string => 'открытый сайт');
    Route::middleware('api')->get('/api/closing-data', fn (): array => ['ok' => true]);
    Fastleo::resources()->flush();

    if ($closed) {
        test()->actingAs(admin(), Fastleo::GUARD)
            ->put(route('fastleo.settings.update', 'closing'), ['closed' => '1', 'message' => $message ?? ''])
            ->assertSessionHasNoErrors();
        auth(Fastleo::GUARD)->logout();
        test()->flushSession();
    }
}

it('has no page and closes nothing until it is switched on', function (): void {
    useFixtureResources('Resources');
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->get('/fastleo/settings/closing')->assertNotFound();
    expect(SiteClosing::closed())->toBeFalse();
});

it('shows visitors a 503 page with the text from the settings', function (): void {
    enableClosing(message: 'Работаем над обновлением');

    $this->get('/closing-page')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '3600')
        ->assertSee('Сайт временно не работает')
        ->assertSee('Работаем над обновлением')
        ->assertSee('noindex', false)
        ->assertDontSee('открытый сайт');
});

it('shows the standard text when the message is empty', function (): void {
    enableClosing();

    $this->get('/closing-page')->assertStatus(503)->assertSee('Загляните позже');
});

it('answers json and api requests with a json 503', function (): void {
    enableClosing(message: 'Скоро вернёмся');

    $this->getJson('/closing-page')->assertStatus(503)->assertExactJson(['message' => 'Скоро вернёмся']);
    $this->getJson('/api/closing-data')->assertStatus(503)->assertJsonPath('message', 'Скоро вернёмся');
});

it('answers a route of the api group with json even without the Accept header', function (): void {
    enableClosing(message: 'Скоро вернёмся');

    $this->get('/api/closing-data')->assertStatus(503)->assertHeader('Content-Type', 'application/json')->assertJsonPath('message', 'Скоро вернёмся');
    $this->get('/closing-page')->assertStatus(503)->assertSee('Скоро вернёмся')->assertDontSee('{"message"', false);
});

it('stays in place when another package rebuilds the middleware groups of the router afterwards', function (): void {
    enableClosing();

    resolve(Kernel::class)->prependToMiddlewarePriority(StartSession::class);

    $this->get('/closing-page')->assertStatus(503);
    $this->getJson('/api/closing-data')->assertStatus(503);
});

it('speaks Russian to visitors whatever the language of the site is', function (): void {
    enableClosing();
    app()->setLocale('en');

    $this->get('/closing-page')->assertStatus(503)->assertSee('Сайт временно не работает')->assertSee('Загляните позже');
    $this->getJson('/closing-page')->assertStatus(503)->assertJsonPath('message', 'Сайт временно не работает. Загляните позже.');
});

it('stays open when the settings cannot be read for any reason, and reports it', function (): void {
    enableClosing(closed: false);
    Exceptions::fake();
    app()->bind(SettingsStore::class, fn (): never => throw new LogicException('сломанное описание страницы'));

    $this->get('/closing-page')->assertOk()->assertSee('открытый сайт');
    Exceptions::assertReported(LogicException::class);
});

it('keeps the site open for a signed-in administrator and tells the templates', function (): void {
    enableClosing();
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->get('/closing-page')->assertOk()->assertSee('открытый сайт');
    expect(View::shared('fastleoSiteClosed'))->toBeTrue();
});

it('closes the site for a user who cannot enter the admin panel', function (): void {
    enableClosing();
    $this->actingAs(User::factory()->create(['fastleo_admin' => false]), Fastleo::GUARD);

    $this->get('/closing-page')->assertStatus(503);
});

it('keeps the admin panel and its login page working and warns that the site is closed', function (): void {
    enableClosing();

    $this->get(route('fastleo.login'))->assertOk();

    $this->actingAs(admin(), Fastleo::GUARD);
    $this->get(route('fastleo.dashboard'))
        ->assertOk()
        ->assertSee('data-site-closed', false)
        ->assertSee('Сайт закрыт для посетителей')
        ->assertSee(route('fastleo.settings.edit', 'closing'), false);
});

it('opens the site again when the box is cleared', function (): void {
    enableClosing();
    $this->actingAs(admin(), Fastleo::GUARD)
        ->put(route('fastleo.settings.update', 'closing'), ['closed' => '0', 'message' => ''])
        ->assertSessionHasNoErrors();
    auth(Fastleo::GUARD)->logout();
    $this->flushSession();

    $this->get('/closing-page')->assertOk()->assertSee('открытый сайт');
    $this->actingAs(admin(), Fastleo::GUARD)->get(route('fastleo.dashboard'))->assertDontSee('data-site-closed', false);
});

it('stays open when the settings cannot be read', function (): void {
    enableClosing(closed: false);
    Cache::flush();
    Schema::drop('fastleo_settings');

    $this->get('/closing-page')->assertOk()->assertSee('открытый сайт');
    expect(SiteClosing::closed())->toBeFalse();
});
