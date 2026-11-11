<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('renders the brand, color, theme and site stylesheets from the config', function (): void {
    config([
        'fastleo.brand.name' => 'Мой сайт',
        'fastleo.brand.logo' => '/images/logo.svg',
        'fastleo.brand.color' => '#0ea5e9',
        'fastleo.theme' => 'dark',
        'fastleo.css' => ['/css/admin.css'],
    ]);

    $response = $this->get(route('fastleo.dashboard'));

    $response->assertOk()
        ->assertSee('<title>Дашборд — Мой сайт</title>', false)
        ->assertSee('src="/images/logo.svg"', false)
        ->assertSee('--fastleo-primary: #0ea5e9;', false)
        ->assertSee('data-theme="dark"', false)
        ->assertSee('href="/css/admin.css"', false);
});

it('falls back to the site name and safe defaults for invalid values', function (): void {
    config([
        'app.name' => 'Мой сайт',
        'fastleo.brand.name' => null,
        'fastleo.brand.color' => 'red; } body { display: none',
        'fastleo.theme' => 'neon',
    ]);

    $response = $this->get(route('fastleo.dashboard'));

    $response->assertOk()
        ->assertSee('<title>Дашборд — Мой сайт</title>', false)
        ->assertSee('--fastleo-primary: #4f46e5;', false)
        ->assertSee('data-theme="system"', false)
        ->assertDontSee('display: none', false);
});

it('escapes the admin name', function (): void {
    $this->actingAs(admin(['name' => '<script>alert(1)</script>']), Fastleo::GUARD);

    $response = $this->get(route('fastleo.dashboard'));

    $response->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('serves the admin in Russian even when the site locale is different', function (): void {
    app()->setLocale('en');

    $response = $this->get(route('fastleo.dashboard'));

    $response->assertOk()->assertSee('Дашборд');
});

it('adds a version to asset urls', function (): void {
    $response = $this->get(route('fastleo.dashboard'));

    expect($response->getContent())
        ->toMatch('#/vendor/fastleo/fastleo\.css\?v=\d{10}"#')
        ->toMatch('#/vendor/fastleo/fastleo\.js\?v=\d{10}"#');
});

it('mounts the admin under the configured path and domain', function (): void {
    config(['fastleo.path' => 'admin', 'fastleo.domain' => 'admin.example.com']);
    Route::setRoutes(new RouteCollection());
    require dirname(__DIR__, 2).'/routes/fastleo.php';
    resolve('url')->setRoutes(Route::getRoutes());

    $response = $this->get('http://admin.example.com/admin');

    $response->assertOk();
    expect(route('fastleo.login'))->toBe('http://admin.example.com/admin/login');
});

it('shows a link to the site in the header when the panel stands under a path', function (): void {
    $this->get(route('fastleo.dashboard'))
        ->assertOk()
        ->assertSee('href="'.url('/').'" target="_blank" rel="noopener"', false)
        ->assertSee('data-site-link', false)
        ->assertSee('На сайт');
});

it('shows no link to the site when the panel is the home page of the domain', function (): void {
    config(['fastleo.path' => '']);

    $this->get(route('fastleo.dashboard'))
        ->assertOk()
        ->assertDontSee('data-site-link', false)
        ->assertDontSee('На сайт');
});

it('takes the home page of the same domain as the site when the panel stands under a path', function (string $path): void {
    config(['fastleo.path' => $path, 'fastleo.domain' => null, 'app.url' => 'https://site.ru']);

    expect(Fastleo::siteUrl())->toBe(url('/'));
})->with(['admin', '/fastleo/']);

it('takes the site from APP_URL when the panel has its own domain', function (string $path, string $domain, string $appUrl, ?string $expected): void {
    config(['fastleo.path' => $path, 'fastleo.domain' => $domain, 'app.url' => $appUrl]);

    expect(Fastleo::siteUrl())->toBe($expected);
})->with([
    'the site is APP_URL' => ['', 'admin.site.ru', 'https://site.ru', 'https://site.ru/'],
    'and the panel keeps a path' => ['admin', 'admin.site.ru', 'https://site.ru/', 'https://site.ru/'],
    'APP_URL keeps a folder' => ['', 'admin.site.ru', 'https://site.ru/sub', 'https://site.ru/sub/'],
    'APP_URL is the domain of the panel itself' => ['', 'crm.site.ru', 'https://CRM.site.ru', null],
    'APP_URL is not a web address' => ['', 'admin.site.ru', 'javascript:alert(1)', null],
    'APP_URL is empty' => ['', 'admin.site.ru', '', null],
]);

it('has no site to go to when the panel is the whole domain', function (): void {
    config(['fastleo.path' => '', 'fastleo.domain' => null, 'app.url' => 'https://crm.site.ru']);

    expect(Fastleo::siteUrl())->toBeNull();
});
