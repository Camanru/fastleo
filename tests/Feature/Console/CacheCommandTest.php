<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Resources\UserResource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Fastleo\ArticleResource;
use Workbench\App\Fastleo\CategoryResource;
use Workbench\App\Fastleo\ContactsSettings;
use Workbench\App\Fastleo\FeedbackResource;
use Workbench\App\Fastleo\PageResource;
use Workbench\App\Fastleo\ReportPage;
use Workbench\App\Fastleo\TopicResource;

beforeEach(function (): void {
    // Кеш — во временной папке: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-cache-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/cache');
    $this->app->useBootstrapPath($this->sandbox);
});

afterEach(function (): void {
    File::deleteDirectory($this->sandbox);
});

it('caches the resource list and serves it from the cache', function (): void {
    $this->artisan('fastleo:cache')->expectsOutputToContain('Список разделов, настроек и страниц закеширован: 8 шт.')->assertSuccessful();

    expect(require Fastleo::resources()->cachePath())->toBe([ArticleResource::class, CategoryResource::class, ContactsSettings::class, FeedbackResource::class, PageResource::class, ReportPage::class, TopicResource::class, UserResource::class]);

    config(['fastleo.resources.path' => '/nonexistent']);
    Fastleo::resources()->flush();
    expect(Fastleo::resources()->all())->toHaveKeys(['articles', 'categories', 'feedback', 'users']);
});

it('does not cache a broken resource description', function (): void {
    useFixtureResources('Registry/Duplicate');

    expect(fn () => Artisan::call('fastleo:cache'))->toThrow(LogicException::class);
    expect(Fastleo::resources()->cachePath())->not->toBeFile();
});

it('clears the cache', function (): void {
    File::put(Fastleo::resources()->cachePath(), '<?php return [];');

    $this->artisan('fastleo:clear')->expectsOutputToContain('Кеш списка разделов удалён.')->assertSuccessful();

    expect(Fastleo::resources()->cachePath())->not->toBeFile();
});

it('runs with optimize and optimize:clear', function (): void {
    expect(ServiceProvider::$optimizeCommands)->toContain('fastleo:cache')
        ->and(ServiceProvider::$optimizeClearCommands)->toContain('fastleo:clear');
});
