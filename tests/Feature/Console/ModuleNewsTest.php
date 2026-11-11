<?php

declare(strict_types=1);

use App\FastleoNews\Models\News;
use Fastleo\Fastleo;
use Fastleo\Installer\DashboardPatcher;
use Fastleo\Support\UserModelPatcher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    // Пишем в временную папку: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-news-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/routes');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
    File::put($this->sandbox.'/composer.json', '{"autoload": {"psr-4": {"App\\\\FastleoNews\\\\": "app/"}}}');
    sandboxWithPest($this->sandbox);
    $this->app->setBasePath($this->sandbox);
    Schema::dropIfExists('news');
    config(['fastleo.resources.path' => $this->sandbox.'/app/Fastleo', 'fastleo.resources.namespace' => 'App\\FastleoNews']);
});

afterEach(function (): void {
    Model::shouldBeStrict(false);
    Factory::flushState();
    File::deleteDirectory($this->sandbox);
});

/**
 * Поднять скопированный модуль «Новости» в тестовом приложении: таблица, классы, маршруты, шаблоны.
 */
function bootCopiedNews(object $test): void
{
    $test->artisan('migrate', ['--path' => $test->sandbox.'/database/migrations', '--realpath' => true])->assertSuccessful();

    // Классы объявляются один раз на процесс: каждый тест копирует модуль в свою папку.
    $classes = [
        'app/Models/News.php' => 'App\\FastleoNews\\Models\\News',
        'app/Fastleo/NewsResource.php' => 'App\\FastleoNews\\NewsResource',
        'app/Fastleo/Dashboard.php' => 'App\\FastleoNews\\Dashboard',
        'database/factories/NewsFactory.php' => 'Database\\Factories\\NewsFactory',
    ];

    foreach ($classes as $file => $class) {
        if (! class_exists($class, false)) {
            require $test->sandbox.'/'.$file;
        }
    }
}

/**
 * Поднять и публичную часть скопированного модуля: контроллер, маршруты и шаблоны сайта.
 */
function bootCopiedNewsSite(object $test): void
{
    bootCopiedNews($test);
    bootSiteRoutes($test, ['App\\FastleoNews\\Http\\Controllers\\NewsController' => 'app/Http/Controllers/NewsController.php'], ['routes/web.php']);
}

it('copies the news module and puts its widget on the dashboard', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['news'], '--no-interaction' => true])
        ->expectsOutputToContain('app/Models/News.php')
        ->expectsOutputToContain('виджеты модулей добавлены')
        ->assertSuccessful();

    $dashboard = File::get($this->sandbox.'/app/Fastleo/Dashboard.php');

    expect($dashboard)->toContain("Table::make('Последние новости')->resource(\\App\\FastleoNews\\NewsResource::class)")
        ->toContain('ServerStatus::make()')
        ->and(glob($this->sandbox.'/database/migrations/*_create_news_table.php'))->toHaveCount(1)
        ->and($this->sandbox.'/app/Http/Controllers/NewsController.php')->toBeFile()
        ->and($this->sandbox.'/resources/views/news/show.blade.php')->toBeFile()
        ->and($this->sandbox.'/resources/views/layouts/site.blade.php')->toBeFile()
        ->and(File::get($this->sandbox.'/routes/web.php'))->toContain('NewsController::class')->not->toContain('fastleo');
});

it('lists the dashboard file among the created ones only when the installation has created it', function (): void {
    Artisan::call('fastleo:module', ['modules' => ['news'], '--no-interaction' => true]);
    expect(Artisan::output())->toContain('app/Fastleo/Dashboard.php');

    Artisan::call('fastleo:module', ['modules' => ['news'], '--no-interaction' => true]);
    expect(Artisan::output())->toContain('Ничего не записано');

    File::deleteDirectory($this->sandbox.'/database');
    Schema::dropIfExists('news');
    File::delete($this->sandbox.'/app/Models/News.php');
    File::delete($this->sandbox.'/app/Fastleo/NewsResource.php');

    Artisan::call('fastleo:module', ['modules' => ['news'], '--no-interaction' => true]);
    expect(Artisan::output())->not->toContain('app/Fastleo/Dashboard.php');
});

it('adds a widget to the dashboard only once and keeps the rest of the file', function (): void {
    $widget = "\\Fastleo\\Widgets\\Stat::make('Один')->value(1),";

    expect(new DashboardPatcher()->add([$widget]))->toBeTrue()
        ->and(new DashboardPatcher()->add([$widget]))->toBeTrue()
        ->and(substr_count(File::get($this->sandbox.'/app/Fastleo/Dashboard.php'), "Stat::make('Один')"))->toBe(1)
        ->and(File::get($this->sandbox.'/app/Fastleo/Dashboard.php'))->toContain('Sections::make()');
});

/**
 * Dashboard сайта с заданным телом списка виджетов.
 */
function siteDashboard(string $sandbox, string $widgets): string
{
    File::ensureDirectoryExists($sandbox.'/app/Fastleo');
    File::put($path = $sandbox.'/app/Fastleo/Dashboard.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\FastleoNews;\n\nfinal class Dashboard\n{\n    public function widgets(): array\n    {\n        return [\n{$widgets}        ];\n    }\n}\n");

    return $path;
}

it('adds a comma after the last widget of the site when it has none', function (): void {
    $path = siteDashboard($this->sandbox, "            \\Fastleo\\Widgets\\ServerStatus::make()\n");

    expect(new DashboardPatcher()->add(["\\Fastleo\\Widgets\\Stat::make('Один')->value(1),"]))->toBeTrue()
        ->and(File::get($path))->toContain("ServerStatus::make(),\n            \\Fastleo\\Widgets\\Stat::make('Один')->value(1),\n        ];")
        ->and(UserModelPatcher::compiles(File::get($path)))->toBeTrue();
});

it('adds a widget to the dashboard of the site saved with Windows line breaks and keeps them', function (): void {
    $path = siteDashboard($this->sandbox, "            \\Fastleo\\Widgets\\ServerStatus::make(),\n");
    File::put($path, str_replace("\n", "\r\n", File::get($path)));

    expect(new DashboardPatcher()->add(["\\Fastleo\\Widgets\\Stat::make('Один')->value(1),"]))->toBeTrue();

    $patched = File::get($path);

    expect($patched)->toContain("Stat::make('Один')")
        ->and(str_replace("\r\n", '', $patched))->not->toContain("\n")
        ->and(UserModelPatcher::compiles($patched))->toBeTrue();
});

it('adds a widget to an empty list of widgets', function (): void {
    $path = siteDashboard($this->sandbox, '');

    expect(new DashboardPatcher()->add(["\\Fastleo\\Widgets\\Stat::make('Один')->value(1),"]))->toBeTrue()
        ->and(File::get($path))->toContain("return [\n            \\Fastleo\\Widgets\\Stat::make('Один')->value(1),\n        ];");
});

it('leaves the dashboard of the site untouched when the result would not compile', function (): void {
    $path = siteDashboard($this->sandbox, "            \\Fastleo\\Widgets\\ServerStatus::make() // без запятой\n");
    $original = File::get($path);

    expect(new DashboardPatcher()->add(["\\Fastleo\\Widgets\\Stat::make('Один')->value(1),"]))->toBeFalse()
        ->and(File::get($path))->toBe($original);
});

it('tells what to add by hand when the dashboard of the site is built differently', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/app/Fastleo');
    File::put($this->sandbox.'/app/Fastleo/Dashboard.php', "<?php\n\nfinal class Dashboard\n{\n    public function widgets(): array\n    {\n        return array_merge([], []);\n    }\n}\n");

    $this->artisan('fastleo:module', ['modules' => ['news'], '--no-interaction' => true])
        ->expectsOutputToContain('Dashboard.php устроен необычно')
        ->expectsOutputToContain("Table::make('Последние новости')->resource(\\App\\FastleoNews\\NewsResource::class)")
        ->assertSuccessful();

    expect(File::get($this->sandbox.'/app/Fastleo/Dashboard.php'))->not->toContain('Последние новости');
});

it('keeps the written modules and asks to add widgets by hand when the dashboard cannot be written', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/app/Fastleo/Dashboard.php');

    $this->artisan('fastleo:module', ['modules' => ['news'], '--no-interaction' => true])
        ->expectsOutputToContain('Dashboard.php устроен необычно')
        ->expectsOutputToContain("Table::make('Последние новости')")
        ->assertSuccessful();

    expect(File::exists($this->sandbox.'/app/Fastleo/NewsResource.php'))->toBeTrue();
});

it('shows only published news whose date has come in the published scope', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['news'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedNews($this);

    News::factory()->create(['slug' => 'opening', 'published_at' => now()]);
    News::factory()->create(['slug' => 'draft', 'is_published' => false]);
    News::factory()->create(['slug' => 'soon', 'published_at' => now()->addDay()]);
    News::factory()->create(['slug' => 'undated', 'published_at' => null]);

    expect(News::query()->published()->pluck('slug')->sort()->values()->all())->toBe(['opening', 'undated']);
});

it('puts undated news among dated ones by the day they were created', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['news'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedNews($this);

    News::factory()->create(['slug' => 'old', 'published_at' => now()->subDays(5)]);
    News::factory()->create(['slug' => 'undated', 'published_at' => null, 'created_at' => now()->subDay()]);
    News::factory()->create(['slug' => 'fresh', 'published_at' => now()->subHour()]);

    expect(News::query()->published()->pluck('slug')->all())->toBe(['fresh', 'undated', 'old']);
});

it('gives the administrator a working news section that passes fastleo:doctor and shows the widget', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['news'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedNews($this);
    Fastleo::resources()->flush();
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->get(route('fastleo.resources.index', 'news'))->assertOk();
    $this->post(route('fastleo.resources.store', 'news'), ['title' => 'Новость', 'slug' => 'novost', 'is_published' => '1'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(News::query()->where('slug', 'novost')->exists())->toBeTrue();

    $this->get(route('fastleo.dashboard'))->assertOk()->assertSee('Последние новости')->assertSee('Новость');

    Artisan::call('fastleo:doctor', ['--json' => true]);
    $problems = collect(json_decode(Artisan::output(), true)['problems'])->where('scope', 'news')->where('level', 'error');

    expect($problems->all())->toBe([]);
});

it('lists the newest news first in the admin list, with or without a publication date', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['news'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedNews($this);
    Fastleo::resources()->flush();
    $this->actingAs(admin(), Fastleo::GUARD);
    News::factory()->create(['title' => 'Старая без даты', 'published_at' => null, 'created_at' => now()->subDays(3)]);
    News::factory()->create(['title' => 'Свежая с датой', 'published_at' => now()->subDays(10), 'created_at' => now()]);
    News::factory()->create(['title' => 'Вчерашняя без даты', 'published_at' => null, 'created_at' => now()->subDay()]);

    $this->get(route('fastleo.resources.index', 'news'))->assertOk()->assertSeeInOrder(['Свежая с датой', 'Вчерашняя без даты', 'Старая без даты']);
});

it('asks after the choice of modules whether the public part is wanted and writes it on yes', function (): void {
    $this->artisan('fastleo:module')
        ->expectsChoice('Какие модули добавить на сайт?', ['news'], moduleOptions())
        ->expectsConfirmation(siteQuestion(), 'yes')
        ->assertSuccessful();

    expect(File::get($this->sandbox.'/routes/web.php'))->toContain('NewsController::class')
        ->and($this->sandbox.'/resources/views/layouts/site.blade.php')->toBeFile();
});

it('writes only the panel part of the module on no and leaves routes/web.php alone', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['news']])
        ->expectsConfirmation(siteQuestion(), 'no')
        ->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo/NewsResource.php')->toBeFile()
        ->and($this->sandbox.'/app/Http')->not->toBeDirectory()
        ->and($this->sandbox.'/resources')->not->toBeDirectory()
        ->and(File::get($this->sandbox.'/routes/web.php'))->not->toContain('NewsController::class');
});

it('asks the question before it writes any file', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/app');
    File::put($this->sandbox.'/app/Fastleo', 'не папка');

    $this->artisan('fastleo:module', ['modules' => ['news']])
        ->expectsConfirmation(siteQuestion(), 'yes')
        ->expectsOutputToContain('файл записать не удалось')
        ->assertFailed();

    expect(File::get($this->sandbox.'/app/Fastleo'))->toBe('не папка')
        ->and($this->sandbox.'/resources')->not->toBeDirectory();
});

it('stops before the question when the panel part is taken', function (): void {
    Schema::create('news', function ($table): void {
        $table->id();
    });

    $this->artisan('fastleo:module', ['modules' => ['news']])
        ->expectsOutputToContain('таблица news уже есть')
        ->assertFailed();
});

it('does not ask and installs only the panel part when the address /news is taken by the site', function (): void {
    Route::get('news', fn (): string => 'свои новости');

    $this->artisan('fastleo:module', ['modules' => ['news']])
        ->expectsOutputToContain('Публичная часть модуля news не добавлена')
        ->expectsOutputToContain('адрес /news уже занят')
        ->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo/NewsResource.php')->toBeFile()
        ->and($this->sandbox.'/resources')->not->toBeDirectory();
});

it('keeps the template of the site and says so', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/resources/views/news');
    File::put($this->sandbox.'/resources/views/news/index.blade.php', 'мой список');

    $this->artisan('fastleo:module', ['modules' => ['news'], '--no-interaction' => true])
        ->expectsOutputToContain('Оставлен ваш шаблон resources/views/news/index.blade.php')
        ->assertSuccessful();

    expect(File::get($this->sandbox.'/resources/views/news/index.blade.php'))->toBe('мой список')
        ->and($this->sandbox.'/resources/views/news/show.blade.php')->toBeFile();
});

it('shows the published news on the site and hides the rest', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['news'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedNewsSite($this);
    Model::shouldBeStrict();

    News::factory()->create(['title' => 'Открытие', 'slug' => 'opening', 'excerpt' => 'Анонс открытия', 'body' => '<p>Текст открытия</p>']);
    News::factory()->create(['title' => 'Черновик', 'slug' => 'draft', 'is_published' => false]);
    News::factory()->create(['title' => 'Скоро', 'slug' => 'soon', 'published_at' => now()->addDay()]);

    $this->get('/news')->assertOk()->assertSee('<main>', false)->assertSee('Открытие')->assertSee('Анонс открытия')->assertDontSee('Черновик')->assertDontSee('Скоро');
    $this->get('/news/opening')->assertOk()->assertSee('<p>Текст открытия</p>', false)->assertSee('og:type" content="article"', false);

    foreach (['draft', 'soon', 'no-such-news', 'Opening', 'a%00b'] as $slug) {
        $this->get('/news/'.$slug)->assertNotFound();
    }
});

it('escapes the fields of the news that visitors can see', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['news'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedNewsSite($this);
    News::factory()->create(['title' => '<script>alert(1)</script>', 'slug' => 'xss', 'excerpt' => '<img src=x onerror=alert(2)>']);

    foreach (['/news', '/news/xss'] as $url) {
        $this->get($url)->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false);
    }
});

it('pages the list of news ten at a time with links to the neighbour pages', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['news'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedNewsSite($this);
    News::factory()->count(11)->create();

    $this->get('/news')->assertOk()->assertSee('rel="next"', false)->assertDontSee('rel="prev"', false);
    $this->get('/news?page=2')->assertOk()->assertSee('rel="prev"', false)->assertDontSee('rel="next"', false);
});
