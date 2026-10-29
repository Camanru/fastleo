<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Health\HealthResult;
use Fastleo\Support\CacheCleaner;
use Fastleo\Support\SchedulerPulse;
use Fastleo\Support\ServerReport;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Строка отчёта по названию.
 *
 * @return array{title: string, value: string, status: ?string, hint: ?string}
 */
function reportRow(string $title): array
{
    foreach (ServerReport::sections() as $section) {
        foreach ($section['rows'] as $row) {
            if ($row['title'] === $title) {
                return $row;
            }
        }
    }

    throw new RuntimeException('Нет строки '.$title);
}

describe('report', function (): void {
    it('warns about debug mode only on a production site', function (): void {
        config(['app.debug' => true]);
        $this->app['env'] = 'local';
        expect(reportRow('Режим отладки (APP_DEBUG)'))->toMatchArray(['value' => 'Включено', 'status' => 'ok', 'hint' => null]);

        $this->app['env'] = 'production';
        $row = reportRow('Режим отладки (APP_DEBUG)');
        expect($row['status'])->toBe('danger')->and($row['hint'])->toContain('APP_DEBUG=false');

        config(['app.debug' => false]);
        expect(reportRow('Режим отладки (APP_DEBUG)')['status'])->toBe('ok');
    });

    it('advises to enable a real queue when it is sync', function (): void {
        config(['queue.default' => 'sync']);
        expect(reportRow('Очередь'))->toMatchArray(['value' => 'sync', 'status' => 'warning']);

        config(['queue.default' => 'database']);
        expect(reportRow('Очередь')['status'])->toBe('ok');
    });

    it('asks for cached config and a real mailer only on production', function (): void {
        config(['mail.default' => 'log']);
        $this->app['env'] = 'local';
        expect(reportRow('Кеш конфигурации и маршрутов')['status'])->toBe('ok')
            ->and(reportRow('Почта')['status'])->toBe('ok');

        $this->app['env'] = 'production';
        expect(reportRow('Кеш конфигурации и маршрутов')['status'])->toBe('warning')
            ->and(reportRow('Кеш конфигурации и маршрутов')['hint'])->toContain('php artisan optimize')
            ->and(reportRow('Почта')['status'])->toBe('warning');

        config(['mail.default' => 'smtp']);
        expect(reportRow('Почта')['status'])->toBe('ok');
    });

    it('reports versions and the time in the admin time zone', function (): void {
        expect(reportRow('PHP')['value'])->toBe(PHP_VERSION)
            ->and(reportRow('Laravel')['value'])->toBe(app()->version())
            ->and(reportRow('База данных')['value'])->toContain(DB::connection()->getDriverName())
            ->and(reportRow('Время сервера')['value'])->toContain(Fastleo::timezone());
    });

    it('checks that storage is writable and notices maintenance mode', function (): void {
        expect(reportRow('Запись в storage и bootstrap/cache')['status'])->toBe('ok')
            ->and(reportRow('Режим обслуживания')['value'])->toBe('Выключено');
    });

    it('counts rows that need attention', function (): void {
        config(['queue.default' => 'sync', 'app.debug' => true]);
        $this->app['env'] = 'production';

        $sections = ServerReport::sections();
        $count = 0;
        foreach ($sections as $section) {
            foreach ($section['rows'] as $row) {
                $count += in_array($row['status'], ['warning', 'danger'], true) ? 1 : 0;
            }
        }

        expect(ServerReport::attention($sections))->toBe($count)->toBeGreaterThanOrEqual(2);
    });
});

describe('widget', function (): void {
    it('shows the attention badge, hints and the labels for screen readers', function (): void {
        config(['queue.default' => 'sync']);

        $this->get(route('fastleo.dashboard'))
            ->assertSee('Требует внимания:')
            ->assertSee('QUEUE_CONNECTION=database')
            ->assertSee('Внимание:')
            ->assertSee('data-status="warning"', false);
    });

    it('says everything is fine when nothing needs attention', function (): void {
        useFixtureResources('Menu');
        config(['queue.default' => 'database']);
        File::ensureDirectoryExists(public_path('storage'));

        $response = $this->get(route('fastleo.dashboard'));

        $response->assertOk()->assertDontSee('data-status="danger"', false);
    });
});

describe('scheduler', function (): void {
    it('remembers when the scheduler ran', function (): void {
        $this->freezeSecond();

        expect(SchedulerPulse::lastRun())->toBeNull()->and(SchedulerPulse::isStale())->toBeTrue();

        // В тестах Laravel не пересылает события консоли Symfony — включаем, как в настоящем запуске.
        resolve(Kernel::class)->rerouteSymfonyCommandEvents();
        $this->artisan('schedule:run')->assertSuccessful();

        expect(SchedulerPulse::lastRun())->toBe(now()->getTimestamp())->and(SchedulerPulse::isStale())->toBeFalse();
    });

    it('does not remember other commands', function (): void {
        resolve(Kernel::class)->rerouteSymfonyCommandEvents();
        $this->artisan('about')->run();

        expect(SchedulerPulse::lastRun())->toBeNull();
    });

    it('warns on a production site that the scheduler is not running', function (): void {
        $this->app['env'] = 'production';
        expect(reportRow('Планировщик'))->toMatchArray(['value' => 'не запускался', 'status' => 'warning'])
            ->and(reportRow('Планировщик')['hint'])->toContain('schedule:run');

        SchedulerPulse::beat();
        $this->travel(3)->minutes();
        expect(reportRow('Планировщик'))->toMatchArray(['value' => 'запускался 3 мин назад', 'status' => 'ok', 'hint' => null]);

        $this->travel(20)->minutes();
        expect(reportRow('Планировщик')['status'])->toBe('warning');
    });

    it('only shows the scheduler on a local site', function (): void {
        $this->app['env'] = 'local';

        expect(reportRow('Планировщик'))->toMatchArray(['value' => 'не запускался', 'status' => null, 'hint' => null]);
    });
});

describe('site checks', function (): void {
    it('shows the own checks of the site with their result', function (): void {
        Fastleo::healthCheck('leads', 'Заявки', fn (): HealthResult => HealthResult::warning('Заявок нет уже 3 дня'));
        Fastleo::healthCheck('crm', 'CRM', fn (): HealthResult => HealthResult::ok());
        Fastleo::healthCheck('mail.out', 'Отправка', fn (): HealthResult => HealthResult::failed('не отправляется'));
        Fastleo::healthCheck('boom', 'Сломанная', fn (): HealthResult => throw new RuntimeException('секрет'));

        $this->get(route('fastleo.dashboard'))
            ->assertOk()
            ->assertSee('Проверки сайта')
            ->assertSeeInOrder(['Заявки', 'Заявок нет уже 3 дня', 'CRM', 'Норма', 'Отправка', 'не отправляется'])
            ->assertSee('Сломанная')
            ->assertDontSee('секрет');
    });

    it('has no section of its own without site checks', function (): void {
        $this->get(route('fastleo.dashboard'))->assertOk()->assertDontSee('Проверки сайта');
    });
});

describe('clearing the cache', function (): void {
    beforeEach(function (): void {
        $this->sandbox = sys_get_temp_dir().'/fastleo-clear-'.getmypid().'-'.uniqid();
        File::ensureDirectoryExists($this->sandbox.'/cache');
        File::ensureDirectoryExists($this->sandbox.'/views');
        $this->app->useBootstrapPath($this->sandbox);
        config(['view.compiled' => $this->sandbox.'/views', 'cache.default' => 'array']);
    });

    afterEach(function (): void {
        File::deleteDirectory($this->sandbox);
    });

    it('formats sizes in Russian units', function (): void {
        expect(CacheCleaner::format(0))->toBe('0 Б')
            ->and(CacheCleaner::format(1023))->toBe('1023 Б')
            ->and(CacheCleaner::format(1536))->toBe('1,5 КБ')
            ->and(CacheCleaner::format(3 * 1024 * 1024))->toBe('3,0 МБ');
    });

    it('counts compiled views and the resource cache before clearing', function (): void {
        file_put_contents($this->sandbox.'/views/a.php', str_repeat('x', 1000));
        file_put_contents(Fastleo::resources()->cachePath(), str_repeat('y', 500));

        expect(CacheCleaner::size())->toBe(1500);
    });

    it('clears the cache by POST and reports the freed size', function (): void {
        file_put_contents($this->sandbox.'/views/a.php', str_repeat('x', 2048));
        file_put_contents(Fastleo::resources()->cachePath(), '<?php return [];');

        $this->post(route('fastleo.dashboard.clear-cache'))
            ->assertRedirect(route('fastleo.dashboard'))
            ->assertSessionHas('fastleo.success', fn (string $message): bool => str_starts_with($message, 'Кеш очищен ('));

        expect(file_exists($this->sandbox.'/views/a.php'))->toBeFalse()
            ->and(file_exists(Fastleo::resources()->cachePath()))->toBeFalse();
    });

    it('can be closed with the clearFastleoCache gate', function (): void {
        file_put_contents($this->sandbox.'/views/a.php', 'x');
        Gate::define('clearFastleoCache', fn (User $user): bool => false);

        $this->get(route('fastleo.dashboard'))->assertOk()->assertDontSee('data-clear-cache', false);
        $this->post(route('fastleo.dashboard.clear-cache'))->assertForbidden();
        expect(file_exists($this->sandbox.'/views/a.php'))->toBeTrue();

        Gate::define('clearFastleoCache', fn (User $user): bool => true);

        $this->get(route('fastleo.dashboard'))->assertSee('data-clear-cache', false);
        $this->post(route('fastleo.dashboard.clear-cache'))->assertRedirect(route('fastleo.dashboard'));
        expect(file_exists($this->sandbox.'/views/a.php'))->toBeFalse();
    });

    it('does not clear the cache by GET or for a guest, and needs a csrf token', function (): void {
        file_put_contents($this->sandbox.'/views/a.php', 'x');

        $this->get(route('fastleo.dashboard.clear-cache'))->assertStatus(405);
        expect(file_exists($this->sandbox.'/views/a.php'))->toBeTrue();

        $this->app['env'] = 'production';
        $this->post(route('fastleo.dashboard.clear-cache'))->assertStatus(419);
        expect(file_exists($this->sandbox.'/views/a.php'))->toBeTrue();

        $this->app['env'] = 'testing';
        $this->app['auth']->guard(Fastleo::GUARD)->logout();
        $this->flushSession();
        $this->post(route('fastleo.dashboard.clear-cache'))->assertRedirect(route('fastleo.login'));
        expect(file_exists($this->sandbox.'/views/a.php'))->toBeTrue();
    });
});
