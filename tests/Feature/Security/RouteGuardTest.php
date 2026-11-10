<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Маршруты панели (все, что называются `fastleo.*`).
 *
 * @return list<LaravelRoute>
 */
function fastleoRoutes(): array
{
    return array_values(array_filter(
        resolve(Router::class)->getRoutes()->getRoutes(),
        fn (LaravelRoute $route): bool => str_starts_with((string) $route->getName(), 'fastleo.'),
    ));
}

/**
 * Адрес маршрута с подставленными значениями параметров, которые проходят ограничения адреса.
 */
function addressOf(LaravelRoute $route): string
{
    $values = ['resource' => 'articles', 'id' => '1', 'import' => '1', 'page' => 'x', 'token' => 'x'];

    if ($route->getName() === 'fastleo.notifications.read') {
        $values['id'] = '00000000-0000-0000-0000-000000000000';
    }

    return '/'.preg_replace_callback('/\{(\w+)\??\}/', fn (array $match): string => $values[$match[1]] ?? 'x', $route->uri());
}

/** Маршруты, которые открыты и гостю: вход, второй шаг входа и восстановление пароля. */
const PUBLIC_ROUTES = [
    'fastleo.login', 'fastleo.login.store', 'fastleo.two-factor.challenge', 'fastleo.two-factor.challenge.store',
    'fastleo.password.request', 'fastleo.password.email', 'fastleo.password.reset', 'fastleo.password.update',
];

it('keeps every route of the admin closed to a guest except the login and the password recovery', function (): void {
    $open = [];

    foreach (fastleoRoutes() as $route) {
        if (in_array($route->getName(), PUBLIC_ROUTES, true)) {
            continue;
        }

        $method = collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD');
        $response = test()->call((string) $method, addressOf($route));

        if ($response->getStatusCode() !== 401 && (! $response->isRedirect() || ! str_ends_with((string) $response->headers->get('Location'), route('fastleo.login', absolute: false)))) {
            $open[] = $route->getName().' → '.$response->getStatusCode();
        }
    }

    expect($open)->toBe([]);
});

it('has exactly the routes that are open to a guest on purpose', function (): void {
    $names = array_map(fn (LaravelRoute $route): ?string => $route->getName(), fastleoRoutes());

    expect(array_diff(PUBLIC_ROUTES, $names))->toBe([]);
});

it('limits the heavy and the password-guessing routes by name', function (): void {
    $limited = [
        'fastleo.password.email' => 'fastleo-password',
        'fastleo.password.update' => 'fastleo-password-reset',
        'fastleo.profile.update' => 'fastleo-profile',
        'fastleo.profile.password' => 'fastleo-profile',
        'fastleo.profile.logout-others' => 'fastleo-profile',
        'fastleo.profile.two-factor.enable' => 'fastleo-profile',
        'fastleo.profile.two-factor.confirm' => 'fastleo-profile',
        'fastleo.profile.two-factor.disable' => 'fastleo-profile',
        'fastleo.profile.two-factor.recovery-codes' => 'fastleo-profile',
        'fastleo.softnetica.check' => 'fastleo-integrations',
        'fastleo.softnetica.disconnect' => 'fastleo-integrations',
        'fastleo.resources.chats' => 'fastleo-integrations',
        'fastleo.resources.export' => 'fastleo-transfer',
        'fastleo.resources.import' => 'fastleo-transfer',
        'fastleo.search' => '60,1',
        'fastleo.markdown' => '60,1',
    ];

    $actual = [];

    foreach (fastleoRoutes() as $route) {
        $throttle = collect($route->gatherMiddleware())->first(fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle:'));

        if (is_string($throttle)) {
            $actual[(string) $route->getName()] = Str::after($throttle, 'throttle:');
        }
    }

    ksort($actual);
    ksort($limited);

    expect($actual)->toBe($limited);
});

it('stops a signed-in administrator who keeps guessing the current password after five tries a minute', function (string $route, string $method): void {
    $this->actingAs(admin(), Fastleo::GUARD);
    Cache::flush();

    foreach (range(1, 5) as $_) {
        expect($this->{$method}(route($route), ['current_password' => 'wrong'])->getStatusCode())->not->toBe(429);
    }

    $this->{$method}(route($route), ['current_password' => 'wrong'])->assertTooManyRequests();
    RateLimiter::clear('fastleo-profile:id:1');
})->with([
    'sign out the others' => ['fastleo.profile.logout-others', 'post'],
    'disable two factor' => ['fastleo.profile.two-factor.disable', 'delete'],
    'new recovery codes' => ['fastleo.profile.two-factor.recovery-codes', 'post'],
    'confirm two factor' => ['fastleo.profile.two-factor.confirm', 'post'],
]);

it('stops the disconnect of SoftNetica and the search for chats after ten tries a minute', function (string $route, string $method, array $parameters): void {
    $this->actingAs(admin(), Fastleo::GUARD);

    foreach (range(1, 10) as $_) {
        expect($this->{$method}(route($route, $parameters))->getStatusCode())->not->toBe(429);
    }

    $this->{$method}(route($route, $parameters))->assertTooManyRequests();
})->with([
    'disconnect SoftNetica' => ['fastleo.softnetica.disconnect', 'post', []],
    'chats' => ['fastleo.resources.chats', 'post', ['notification-bots', 'recipients']],
]);
