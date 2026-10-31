<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Notifications\NotificationBotResource;
use Fastleo\Publishing\ChannelResource;
use Fastleo\Publishing\PublicationResource;
use Fastleo\Tests\Fixtures\Registry\Duplicate\FirstResource;
use Fastleo\Tests\Fixtures\Registry\Duplicate\SecondResource;
use Fastleo\Tests\Fixtures\Registry\TakenBuiltIn\SiteBotsResource;
use Fastleo\Tests\Fixtures\Resources\GuardedCategoryResource;
use Illuminate\Support\Facades\File;
use Workbench\App\Fastleo\CategoryResource;

it('discovers concrete resource classes in the configured folder', function (): void {
    useFixtureResources('Resources');

    expect(Fastleo::resources()->all())->toBe(['guarded-categories' => GuardedCategoryResource::class]);
});

it('returns null for an unknown slug', function (): void {
    expect(Fastleo::resources()->find('nothing'))->toBeNull();
});

it('returns the same resource object for repeated lookups', function (): void {
    expect(Fastleo::resources()->find('categories'))
        ->toBeInstanceOf(CategoryResource::class)
        ->toBe(Fastleo::resources()->find('categories'));
});

it('finds nothing when the folder does not exist', function (): void {
    config(['fastleo.resources.path' => '/nonexistent/fastleo', 'fastleo.users.resource' => null, 'fastleo.notifications.bots' => null, 'fastleo.publishing.channels' => null, 'fastleo.publishing.publications' => null]);
    Fastleo::resources()->flush();

    expect(Fastleo::resources()->all())->toBe([]);
});

it('skips a built-in section whose address the site already took', function (): void {
    useFixtureResources('Registry/TakenBuiltIn');
    config(['fastleo.notifications.bots' => NotificationBotResource::class]);
    Fastleo::resources()->flush();

    expect(Fastleo::resources()->all())->toBe(['notification-bots' => SiteBotsResource::class]);
});

it('explains a mistake in a resource description', function (string $folder, string $message): void {
    useFixtureResources('Registry/'.$folder);

    expect(fn (): array => Fastleo::resources()->all())->toThrow(LogicException::class, $message);
})->with([
    'two resources with one slug' => ['Duplicate', 'Адрес раздела «items» у ресурсов '.SecondResource::class.' и '.FirstResource::class.' совпадает'],
    'slug taken by the admin' => ['Reserved', 'Адрес раздела «login»'],
    'slug with forbidden characters' => ['BadSlug', 'Адрес раздела «Bad_Slug»'],
    'no model' => ['NoModel', 'не указана модель'],
]);

it('explains a manual order column with forbidden characters', function (): void {
    useFixtureResources('Registry/BadColumn');

    expect(fn (): array => Fastleo::resources()->all())->toThrow(LogicException::class, 'Колонка «sort order; drop»');
});

it('explains a custom page with a bad address', function (): void {
    useFixtureResources('Registry/BadPageSlug');

    expect(fn (): array => Fastleo::resources()->pages())->toThrow(LogicException::class, 'Адрес «Bad_Page»');
});

it('refuses a class from the container that is not a resource', function (): void {
    $class = Fastleo::resources()->all()['articles'];
    $this->app->bind($class, fn (): stdClass => new stdClass);

    expect(fn (): ?\Fastleo\Resource => Fastleo::resources()->find('articles'))->toThrow(LogicException::class, 'не найден или не наследует');
});

describe('cache', function (): void {
    beforeEach(function (): void {
        // Кеш — во временной папке: общий каркас Testbench читают параллельные процессы тестов.
        $this->sandbox = sys_get_temp_dir().'/fastleo-registry-'.getmypid().'-'.uniqid();
        File::ensureDirectoryExists($this->sandbox.'/cache');
        $this->app->useBootstrapPath($this->sandbox);
        Fastleo::resources()->flush();
    });

    afterEach(function (): void {
        File::deleteDirectory($this->sandbox);
    });

    it('reads the resource list from the cache file when it exists', function (): void {
        File::put(Fastleo::resources()->cachePath(), '<?php return '.var_export([GuardedCategoryResource::class], true).';');

        expect(Fastleo::resources()->all())->toBe(['guarded-categories' => GuardedCategoryResource::class, 'notification-bots' => NotificationBotResource::class, 'channels' => ChannelResource::class, 'publications' => PublicationResource::class]);

        config(['fastleo.notifications.bots' => null, 'fastleo.publishing.channels' => null, 'fastleo.publishing.publications' => null]);
        Fastleo::resources()->flush();
        expect(Fastleo::resources()->all())->toBe(['guarded-categories' => GuardedCategoryResource::class]);
    });

    it('explains a cached class that no longer exists', function (): void {
        File::put(Fastleo::resources()->cachePath(), "<?php return ['App\\\\Fastleo\\\\GoneResource'];");

        expect(fn (): array => Fastleo::resources()->all())->toThrow(LogicException::class, 'php artisan fastleo:cache');
    });
});

it('does not carry resource objects over to the next request of a long-running worker', function (): void {
    $first = Fastleo::resources()->find('categories');

    app()->forgetScopedInstances();

    expect(Fastleo::resources()->find('categories'))->not->toBe($first);
});
