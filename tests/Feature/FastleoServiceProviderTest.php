<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\FastleoServiceProvider;
use Fastleo\Http\Middleware\CloseSiteForVisitors;
use Illuminate\Auth\SessionGuard;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

it('registers the fastleo guard over the configured provider with the remember period in minutes', function (): void {
    config(['auth.guards' => Arr::except(config()->array('auth.guards'), Fastleo::GUARD)]);
    config(['fastleo.auth.provider' => 'admins', 'fastleo.auth.remember_days' => 7]);

    new FastleoServiceProvider($this->app)->register();

    expect(config('auth.guards.fastleo'))->toBe([
        'driver' => 'session',
        'provider' => 'admins',
        'remember' => 7 * 24 * 60,
    ]);
});

it('keeps a fastleo guard described by the site', function (): void {
    $guard = ['driver' => 'session', 'provider' => 'users', 'remember' => 60];
    config(['auth.guards.fastleo' => $guard]);

    new FastleoServiceProvider($this->app)->register();

    expect(config('auth.guards.fastleo'))->toBe($guard);
});

it('serves the admin from the site root when the path is empty', function (): void {
    config(['fastleo.path' => '']);
    Route::setRoutes(new RouteCollection);
    require dirname(__DIR__, 2).'/routes/fastleo.php';
    resolve('url')->setRoutes(Route::getRoutes());
    $this->actingAs(admin(), Fastleo::GUARD);

    $response = $this->get('/');

    $response->assertOk()->assertSee('Дашборд');
    expect(route('fastleo.login', absolute: false))->toBe('/login');
});

it('explains a fastleo guard that does not use the session driver', function (): void {
    config(['auth.guards.fastleo' => ['driver' => 'token', 'provider' => 'users']]);
    Auth::forgetGuards();

    expect(fn (): SessionGuard => Fastleo::guard())->toThrow(LogicException::class, 'драйвере session');
});

it('adds the sitemap and robots.txt routes at boot only when they are switched on', function (): void {
    Route::setRoutes(new RouteCollection);
    new FastleoServiceProvider($this->app)->boot();
    Route::getRoutes()->refreshNameLookups();

    expect(Route::has('fastleo.sitemap'))->toBeFalse()
        ->and(Route::has('fastleo.robots'))->toBeFalse();

    config(['fastleo.sitemap.enabled' => true, 'fastleo.robots.enabled' => true]);
    new FastleoServiceProvider($this->app)->boot();
    Route::getRoutes()->refreshNameLookups();

    expect(Route::has('fastleo.sitemap'))->toBeTrue()
        ->and(Route::has('fastleo.robots'))->toBeTrue();
});

it('attaches the site closing check to the web group at boot when it is switched on', function (): void {
    /** @var Illuminate\Foundation\Http\Kernel $kernel */
    $kernel = resolve(Kernel::class);

    expect($kernel->getMiddlewareGroups()['web'])->not->toContain(CloseSiteForVisitors::class);

    config(['fastleo.closing.enabled' => true]);
    new FastleoServiceProvider($this->app)->boot();

    expect($kernel->getMiddlewareGroups()['web'])->toContain(CloseSiteForVisitors::class);
});

it('does not touch commands and the schedule when the site is served over HTTP', function (): void {
    $scheduled = fn (): int => collect(resolve(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'fastleo:prune'))->count();
    $before = $scheduled();

    $console = new ReflectionProperty($this->app, 'isRunningInConsole');
    $console->setValue($this->app, false);

    new FastleoServiceProvider($this->app)->boot();
    $this->app->forgetInstance(Schedule::class);

    expect($before)->toBe(1)->and($scheduled())->toBe(1);
});
