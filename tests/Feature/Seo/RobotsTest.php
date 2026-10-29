<?php

declare(strict_types=1);

use Fastleo\Support\Robots;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config(['app.url' => 'https://site.test']);
});

/**
 * Включить robots.txt: маршрут подключается провайдером при старте только по настройке, поэтому в тесте — вручную.
 *
 * @param  array<string, mixed>  $settings
 */
function enableRobots(array $settings = []): void
{
    config(['fastleo.robots' => [...config('fastleo.robots'), 'enabled' => true, ...$settings]]);
    require __DIR__.'/../../../routes/robots.php';
    Route::getRoutes()->refreshNameLookups();
}

it('has no address until it is switched on', function (): void {
    $this->get('/robots.txt')->assertNotFound();
    expect(Route::has('fastleo.robots'))->toBeFalse();
});

it('gives the lines of the config as plain text without signing in', function (): void {
    enableRobots(['lines' => ['User-agent: *', 'Disallow: /cart', 'Crawl-delay: 5']]);

    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent("User-agent: *\nDisallow: /cart\nCrawl-delay: 5\n");
});

it('does not name the admin address', function (): void {
    enableRobots();

    $this->get('/robots.txt')->assertOk()->assertDontSee('fastleo')->assertDontSee(config('fastleo.path'));
});

it('points to the sitemap only when the sitemap is switched on', function (): void {
    expect(Robots::text())->not->toContain('Sitemap:');

    config(['fastleo.sitemap.enabled' => true]);

    expect(Robots::text())->toBe("User-agent: *\nDisallow:\n\nSitemap: https://site.test/sitemap.xml\n");
});

it('keeps a line of the config on one line and skips empty ones', function (): void {
    config(['fastleo.robots.lines' => ["User-agent: *\r\nDisallow: /", '', 5, 'Disallow: /tmp']]);

    expect(Robots::text())->toBe("User-agent: *Disallow: /\nDisallow: /tmp\n");
});
