<?php

declare(strict_types=1);

use App\Models\Page;
use Fastleo\Fastleo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    // Пишем в временную папку: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-pages-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/routes');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
    File::put($this->sandbox.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
    $this->app->setBasePath($this->sandbox);
    Schema::dropIfExists('pages'); // таблица демо-сайта: модуль не ставится поверх чужой
    config(['fastleo.resources.path' => $this->sandbox.'/app/Fastleo', 'fastleo.resources.namespace' => 'App\\FastleoPages']);
});

afterEach(function (): void {
    Model::preventLazyLoading(false);
    Model::preventSilentlyDiscardingAttributes(false);
    Factory::flushState();
    File::deleteDirectory($this->sandbox);
});

/**
 * Поднять скопированный модуль «Страницы» в тестовом приложении: таблица, классы, фабрика.
 */
function bootCopiedPages(object $test): void
{
    $test->artisan('migrate', ['--path' => $test->sandbox.'/database/migrations', '--realpath' => true])->assertSuccessful();

    // Классы объявляются один раз на процесс: каждый тест копирует модуль в свою папку.
    $classes = [
        'app/Models/Page.php' => 'App\\Models\\Page',
        'app/Fastleo/PageResource.php' => 'App\\FastleoPages\\PageResource',
        'database/factories/PageFactory.php' => 'Database\\Factories\\PageFactory',
    ];

    foreach ($classes as $file => $class) {
        if (! class_exists($class, false)) {
            require $test->sandbox.'/'.$file;
        }
    }

    // Как на обычном сайте: фабрика ищется в Database\Factories (демо-сайт подменяет правило своим).
    Factory::guessFactoryNamesUsing(fn (string $model): string => 'Database\\Factories\\'.class_basename($model).'Factory');
}

it('copies the pages module as an admin section only: no routes, controllers or templates', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages']])
        ->expectsOutputToContain('app/Models/Page.php')
        ->assertSuccessful();

    expect($this->sandbox.'/app/Models/Page.php')->toBeFile()
        ->and($this->sandbox.'/app/Fastleo/PageResource.php')->toBeFile()
        ->and(File::get($this->sandbox.'/app/Models/Page.php'))->toContain('namespace App\Models;')
        ->and(glob($this->sandbox.'/database/migrations/*_create_pages_table.php'))->toHaveCount(1)
        ->and($this->sandbox.'/database/seeders/PageSeeder.php')->toBeFile()
        ->and($this->sandbox.'/app/Http')->not->toBeDirectory()
        ->and($this->sandbox.'/routes/site')->not->toBeDirectory()
        ->and($this->sandbox.'/resources')->not->toBeDirectory()
        ->and(File::get($this->sandbox.'/routes/web.php'))->not->toContain('siteRoutes');
});

it('copies the site tests only when the site uses Pest', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages']])
        ->expectsOutputToContain('на сайте нет Pest')
        ->assertSuccessful();

    expect($this->sandbox.'/tests/Feature/Site/PagesTest.php')->not->toBeFile();

    File::deleteDirectory($this->sandbox.'/app');
    File::deleteDirectory($this->sandbox.'/database');
    File::put($this->sandbox.'/composer.json', '{"require-dev": {"pestphp/pest": "^4.0"}, "autoload": {"psr-4": {"App\\\\": "app/"}}}');

    // Pest в composer.json, но не инициализирован (нет tests/Pest.php): тесты без него падают, их не копируем.
    $this->artisan('fastleo:module', ['modules' => ['pages']])
        ->expectsOutputToContain('pest --init')
        ->assertSuccessful();

    expect($this->sandbox.'/tests/Feature/Site/PagesTest.php')->not->toBeFile();

    File::deleteDirectory($this->sandbox.'/app');
    File::deleteDirectory($this->sandbox.'/database');
    File::ensureDirectoryExists($this->sandbox.'/tests');
    File::put($this->sandbox.'/tests/Pest.php', '<?php');

    $this->artisan('fastleo:module', ['modules' => ['pages']])->assertSuccessful();

    expect($this->sandbox.'/tests/Feature/Site/PagesTest.php')->toBeFile();
});

it('refuses to install over an existing pages table', function (): void {
    Schema::create('pages', function ($table): void {
        $table->id();
    });

    $this->artisan('fastleo:module', ['modules' => ['pages']])
        ->expectsOutputToContain('таблица pages уже есть')
        ->assertFailed();

    expect($this->sandbox.'/app/Models/Page.php')->not->toBeFile();
});

it('keeps the published pages and takes the sort column in strict mode', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages']])->assertSuccessful();
    bootCopiedPages($this);
    Model::preventLazyLoading();
    Model::preventSilentlyDiscardingAttributes();

    Page::query()->create(['title' => 'Порядок', 'slug' => 'order', 'sort' => 5]);
    Page::factory()->create(['slug' => 'closed', 'is_published' => false]);

    expect(Page::query()->where('slug', 'order')->value('sort'))->toBe(5)
        ->and(Page::query()->published()->pluck('slug')->all())->toBe(['order']);
});

it('gives the administrator a working pages section that passes fastleo:doctor', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages']])->assertSuccessful();
    bootCopiedPages($this);
    Fastleo::resources()->flush();
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->get(route('fastleo.resources.index', 'site-pages'))->assertOk();
    $this->get(route('fastleo.resources.create', 'site-pages'))->assertOk();
    $this->post(route('fastleo.resources.store', 'site-pages'), ['title' => 'Контакты', 'slug' => 'contacts', 'is_published' => '1'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $page = Page::query()->where('slug', 'contacts')->firstOrFail();

    $this->get(route('fastleo.resources.edit', ['site-pages', $page->id]))->assertOk();

    Artisan::call('fastleo:doctor', ['--json' => true]);
    $problems = collect(json_decode(Artisan::output(), true)['problems'])->where('scope', 'site-pages')->where('level', 'error');

    expect($problems->all())->toBe([]);
});
