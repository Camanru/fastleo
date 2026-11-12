<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Health\HealthChecks;
use Fastleo\Health\HealthResult;
use Fastleo\Support\CacheCleaner;
use Fastleo\Support\SchedulerPulse;
use Fastleo\Support\ServerReport;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Console\Exception\CommandNotFoundException;
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

/**
 * Открыть панель с другого адреса (`null` — запрос без Host, как в консоли); `url()` запоминает корень, поэтому сбрасываем.
 */
function openedFrom(?string $host): void
{
    $host === null ? request()->headers->remove('host') : request()->headers->set('host', $host);
    url()->setRequest(request());
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

    it('says that the queue is off instead of failing when QUEUE_CONNECTION is null', function (): void {
        config(['queue.default' => null]);

        expect(reportRow('Очередь'))->toMatchArray(['value' => 'null', 'status' => 'warning'])
            ->and(reportRow('Очередь')['hint'])->toContain('отбрасываются');
    });

    it('warns when the request came through a proxy that Laravel does not trust', function (string $header, string $value): void {
        request()->headers->set($header, $value);

        $row = reportRow('Адрес посетителя');

        expect($row['status'])->toBe('warning')
            ->and($row['value'])->toBe('127.0.0.1')
            ->and($row['hint'])->toContain('trustProxies')->toContain('127.0.0.1');
    })->with([
        'cloudflare' => ['CF-Connecting-IP', '203.0.113.7'],
        'forwarded list' => ['X-Forwarded-For', '203.0.113.7, 10.0.0.9'],
    ]);

    it('says nothing about proxies without forwarded headers or when the address is the same', function (): void {
        expect(fn (): array => reportRow('Адрес посетителя'))->toThrow(RuntimeException::class, 'Нет строки Адрес посетителя');

        request()->headers->set('X-Forwarded-For', '127.0.0.1');
        expect(fn (): array => reportRow('Адрес посетителя'))->toThrow(RuntimeException::class, 'Нет строки Адрес посетителя');
    });

    it('shows an error when the panel is opened from an address other than APP_URL', function (string $configured, string $actual, string $shown): void {
        config(['app.url' => $configured]);
        openedFrom($actual);

        $row = reportRow('Адрес сайта (APP_URL)');

        expect($row['status'])->toBe('danger')
            ->and($row['value'])->toBe($shown)
            ->and($row['hint'])->toContain('APP_URL='.$shown)->toContain($actual === 'localhost' ? 'http://localhost,' : $actual)->toContain('php artisan config:clear');
    })->with([
        'other host' => ['http://localhost:8000', 'site.test', 'http://localhost:8000'],
        'other port' => ['http://localhost:8000', 'localhost', 'http://localhost:8000'],
    ]);

    it('only warns when the panel is opened with www and APP_URL is without it: files still open', function (): void {
        config(['app.url' => 'http://site.example']);
        openedFrom('www.site.example');

        $row = reportRow('Адрес сайта (APP_URL)');

        expect($row['status'])->toBe('warning')
            ->and($row['hint'])->toContain('http://www.site.example')->toContain('переадресацию')->not->toContain('не откроются');
    });

    it('says nothing about APP_URL when the address matches, differs only in case or default port, or the request is not from a browser', function (): void {
        config(['app.url' => 'http://Localhost:8000/panel']);
        openedFrom('localhost:8000');
        expect(fn (): array => reportRow('Адрес сайта (APP_URL)'))->toThrow(RuntimeException::class);

        config(['app.url' => 'http://SITE.example:80']);
        openedFrom('site.example');
        expect(fn (): array => reportRow('Адрес сайта (APP_URL)'))->toThrow(RuntimeException::class);

        config(['app.url' => 'not a url']);
        expect(fn (): array => reportRow('Адрес сайта (APP_URL)'))->toThrow(RuntimeException::class);

        // APP_URL=null в .env.
        config(['app.url' => null]);
        expect(fn (): array => reportRow('Адрес сайта (APP_URL)'))->toThrow(RuntimeException::class);

        config(['app.url' => 'https://site.example:443']);
        openedFrom(null);
        expect(fn (): array => reportRow('Адрес сайта (APP_URL)'))->toThrow(RuntimeException::class);
    });

    it('says nothing about APP_URL when the panel has its own domain or the scheme is forced to https', function (): void {
        config(['app.url' => 'https://site.example', 'fastleo.domain' => 'admin.site.example']);
        openedFrom('admin.site.example');
        expect(fn (): array => reportRow('Адрес сайта (APP_URL)'))->toThrow(RuntimeException::class);

        config(['fastleo.domain' => null]);
        openedFrom('site.example');
        URL::forceScheme('https');
        expect(fn (): array => reportRow('Адрес сайта (APP_URL)'))->toThrow(RuntimeException::class);
    });

    it('blames the untrusted proxy, not APP_URL, when only the scheme differs behind it', function (): void {
        config(['app.url' => 'https://site.example']);
        openedFrom('site.example');
        request()->headers->set('X-Forwarded-Proto', 'https');
        expect(fn (): array => reportRow('Адрес сайта (APP_URL)'))->toThrow(RuntimeException::class);

        openedFrom('other.example');
        expect(reportRow('Адрес сайта (APP_URL)')['status'])->toBe('danger');
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

    it('counts failed jobs only when the failed job log can count', function (): void {
        $this->app->instance('queue.failer', new NullFailedJobProvider());
        expect(reportRow('Упавшие задачи очереди'))->toMatchArray(['value' => '0', 'status' => 'ok']);

        $this->app->instance('queue.failer', Mockery::mock(FailedJobProviderInterface::class));
        $titles = collect(ServerReport::sections())->flatMap(fn (array $section): array => array_column($section['rows'], 'title'));

        expect($titles->contains('Упавшие задачи очереди'))->toBeFalse();
    });

    it('shows a dash instead of the free disk space when the disk cannot be measured', function (): void {
        $this->app->setBasePath(sys_get_temp_dir().'/fastleo-no-such-folder-'.uniqid());

        expect(reportRow('Свободное место'))->toMatchArray(['value' => '—', 'status' => 'ok', 'hint' => null]);
    });

    it('reads sizes from php.ini in bytes', function (string $value, int $bytes): void {
        $method = new ReflectionMethod(ServerReport::class, 'bytes');

        expect($method->invoke(null, $value))->toBe($bytes);
    })->with([
        'gigabytes' => ['2G', 2 * 1024 ** 3],
        'megabytes' => ['8m', 8 * 1024 ** 2],
        'kilobytes' => [' 512K ', 512 * 1024],
        'plain bytes' => ['1048576', 1048576],
    ]);

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

describe('storage link', function (): void {
    beforeEach(function (): void {
        // Публичная папка во временной папке: общий каркас Testbench читают параллельные процессы тестов.
        $this->publicDir = sys_get_temp_dir().'/fastleo-public-'.getmypid().'-'.uniqid();
        File::ensureDirectoryExists($this->publicDir);
        $this->app->usePublicPath($this->publicDir);
    });

    afterEach(function (): void {
        File::deleteDirectory($this->publicDir);
    });

    it('warns about a missing public/storage link when the files are on the public disk', function (): void {
        config(['fastleo.files.disk' => 'public', 'filesystems.disks.public.driver' => 'local']);

        expect(reportRow('Ссылка storage'))->toMatchArray(['status' => 'warning'])
            ->and(reportRow('Ссылка storage')['hint'])->toContain('storage:link');

        File::ensureDirectoryExists($this->publicDir.'/storage');

        expect(reportRow('Ссылка storage'))->toMatchArray(['status' => 'ok', 'hint' => null]);
    });

    it('does not ask for the link when the files are not on the public disk', function (): void {
        config(['fastleo.files.disk' => 's3', 'filesystems.disks.s3' => ['driver' => 's3']]);

        expect(reportRow('Ссылка storage'))->toMatchArray(['status' => 'ok', 'hint' => null]);
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

    it('shows addresses and emails of a check in the panel itself but hides them in the report to SoftNetica', function (): void {
        Fastleo::healthCheck('site', 'Сайт https://example.com', fn (): HealthResult => HealthResult::failed('Не открывается https://example.com/price, почта admin@example.com'));

        $this->get(route('fastleo.dashboard'))
            ->assertOk()
            ->assertSee('Сайт https://example.com')
            ->assertSee('Не открывается https://example.com/price, почта admin@example.com');

        $reported = collect(resolve(HealthChecks::class)->collect())->firstWhere('name', 'site');

        expect($reported)->toMatchArray(['label' => 'Сайт [ссылка]'])
            ->and(json_encode($reported, JSON_UNESCAPED_UNICODE))->not->toContain('example.com');
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

    it('counts the files of the file cache store and skips folders that do not exist', function (): void {
        File::ensureDirectoryExists($this->sandbox.'/app-cache');
        file_put_contents($this->sandbox.'/app-cache/entry', str_repeat('z', 700));
        config([
            'cache.default' => 'file',
            'cache.stores.file.driver' => 'file',
            'cache.stores.file.path' => $this->sandbox.'/app-cache',
            'view.compiled' => $this->sandbox.'/not-created',
        ]);

        expect(CacheCleaner::size())->toBe(700);
    });

    it('counts the size for the dashboard once a minute and again after clearing', function (): void {
        file_put_contents($this->sandbox.'/views/a.php', str_repeat('x', 1000));
        expect(CacheCleaner::cachedSize())->toBe(1000);

        file_put_contents($this->sandbox.'/views/b.php', str_repeat('x', 500));
        expect(CacheCleaner::cachedSize())->toBe(1000);

        $this->travel(61)->seconds();
        expect(CacheCleaner::cachedSize())->toBe(1500);

        file_put_contents($this->sandbox.'/views/c.php', str_repeat('x', 100));
        CacheCleaner::clear();
        file_put_contents($this->sandbox.'/views/d.php', str_repeat('x', 40));

        expect(CacheCleaner::cachedSize())->toBe(40);
    });

    it('forgets the list of resources and the settings on its own, without the commands of the package', function (): void {
        // Настройки лежат в кеше по умолчанию, который `cache:clear` тоже чистит: проверяем забывание отдельно от него.
        Cache::forever('fastleo.settings', ['contacts' => '{"phone":"+7 000"}']);
        file_put_contents(Fastleo::resources()->cachePath(), '<?php return [];');

        CacheCleaner::forgetResources();

        expect(file_exists(Fastleo::resources()->cachePath()))->toBeFalse()
            ->and(Cache::has('fastleo.settings'))->toBeFalse();
    });

    it('clears the cache in a web request, where the commands of the package are not registered', function (): void {
        $console = resolve(Kernel::class);
        // Консоль, как в веб-запросе: команды Laravel работают, а `fastleo:*` нет; все вызовы записываются.
        $web = new class($console) implements Kernel
        {
            /** @var list<string> */
            public array $called = [];

            public function __construct(private readonly Kernel $inner) {}

            public function bootstrap(): void
            {
                $this->inner->bootstrap();
            }

            public function handle($input, $output = null): int
            {
                return $this->inner->handle($input, $output);
            }

            public function call($command, array $parameters = [], $outputBuffer = null): int
            {
                $this->called[] = $command;

                return str_starts_with($command, 'fastleo:') ? throw new CommandNotFoundException($command) : $this->inner->call($command, $parameters, $outputBuffer);
            }

            public function queue($command, array $parameters = []): mixed
            {
                return $this->inner->queue($command, $parameters);
            }

            public function all(): array
            {
                return $this->inner->all();
            }

            public function output(): string
            {
                return $this->inner->output();
            }

            public function terminate($input, $status): void
            {
                $this->inner->terminate($input, $status);
            }
        };
        app()->instance(Kernel::class, $web);
        Artisan::clearResolvedInstance(Kernel::class);

        Cache::forever('fastleo.settings', ['contacts' => '{"phone":"+7 000"}']);
        file_put_contents(Fastleo::resources()->cachePath(), '<?php return [];');

        CacheCleaner::clear();

        expect($web->called)->toBe(['cache:clear', 'view:clear'])
            ->and(file_exists(Fastleo::resources()->cachePath()))->toBeFalse()
            ->and(Cache::has('fastleo.settings'))->toBeFalse();
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

    it('leaves the cache of config, routes and events alone', function (): void {
        $kept = [app()->getCachedConfigPath(), app()->getCachedRoutesPath(), app()->getCachedEventsPath()];

        foreach ($kept as $path) {
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, '<?php return [];');
        }

        file_put_contents($this->sandbox.'/views/a.php', str_repeat('x', 100));

        $this->post(route('fastleo.dashboard.clear-cache'))->assertRedirect(route('fastleo.dashboard'));

        expect(array_map(file_exists(...), $kept))->toBe([true, true, true])
            ->and(file_exists($this->sandbox.'/views/a.php'))->toBeFalse()
            ->and(CacheCleaner::size())->toBe(0);
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
