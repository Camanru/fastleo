<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\FastleoServiceProvider;
use Illuminate\Auth\SessionGuard;
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
