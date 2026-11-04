<?php

declare(strict_types=1);

use App\Models\News;
use Fastleo\Fastleo;
use Fastleo\Installer\DashboardPatcher;
use Fastleo\Support\UserModelPatcher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    // Пишем в временную папку: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-news-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/routes');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
    File::put($this->sandbox.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
    $this->app->setBasePath($this->sandbox);
    Schema::dropIfExists('news');
    config(['fastleo.resources.path' => $this->sandbox.'/app/Fastleo', 'fastleo.resources.namespace' => 'App\\FastleoNews']);
});

afterEach(function (): void {
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
        'app/Models/News.php' => 'App\\Models\\News',
        'app/Fastleo/NewsResource.php' => 'App\\FastleoNews\\NewsResource',
        'app/Fastleo/Dashboard.php' => 'App\\FastleoNews\\Dashboard',
        'database/factories/NewsFactory.php' => 'Database\\Factories\\NewsFactory',
    ];

    foreach ($classes as $file => $class) {
        if (! class_exists($class, false)) {
            require $test->sandbox.'/'.$file;
        }
    }

    Factory::guessFactoryNamesUsing(fn (string $model): string => 'Database\\Factories\\'.class_basename($model).'Factory');
}

it('copies the news module and puts its widget on the dashboard', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['news']])
        ->expectsOutputToContain('app/Models/News.php')
        ->expectsOutputToContain('виджеты модулей добавлены')
        ->assertSuccessful();

    $dashboard = File::get($this->sandbox.'/app/Fastleo/Dashboard.php');

    expect($dashboard)->toContain("Table::make('Последние новости')->resource(\\App\\FastleoNews\\NewsResource::class)")
        ->toContain('ServerStatus::make()')
        ->and(glob($this->sandbox.'/database/migrations/*_create_news_table.php'))->toHaveCount(1)
        ->and($this->sandbox.'/routes/site')->not->toBeDirectory()
        ->and($this->sandbox.'/resources')->not->toBeDirectory();
});

it('lists the dashboard file among the created ones only when the installation has created it', function (): void {
    Artisan::call('fastleo:module', ['modules' => ['news']]);
    expect(Artisan::output())->toContain('app/Fastleo/Dashboard.php');

    Artisan::call('fastleo:module', ['modules' => ['news']]);
    expect(Artisan::output())->toContain('Ничего не записано');

    File::deleteDirectory($this->sandbox.'/database');
    Schema::dropIfExists('news');
    File::delete($this->sandbox.'/app/Models/News.php');
    File::delete($this->sandbox.'/app/Fastleo/NewsResource.php');

    Artisan::call('fastleo:module', ['modules' => ['news']]);
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

    $this->artisan('fastleo:module', ['modules' => ['news']])
        ->expectsOutputToContain('Dashboard.php устроен необычно')
        ->expectsOutputToContain("Table::make('Последние новости')")
        ->assertSuccessful();

    expect(File::get($this->sandbox.'/app/Fastleo/Dashboard.php'))->not->toContain('Последние новости');
});

it('keeps the written modules and asks to add widgets by hand when the dashboard cannot be written', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/app/Fastleo/Dashboard.php');

    $this->artisan('fastleo:module', ['modules' => ['news']])
        ->expectsOutputToContain('Dashboard.php устроен необычно')
        ->expectsOutputToContain("Table::make('Последние новости')")
        ->assertSuccessful();

    expect(File::exists($this->sandbox.'/app/Fastleo/NewsResource.php'))->toBeTrue();
});

it('shows only published news whose date has come in the published scope', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['news']])->assertSuccessful();
    bootCopiedNews($this);

    News::factory()->create(['slug' => 'opening', 'published_at' => now()]);
    News::factory()->create(['slug' => 'draft', 'is_published' => false]);
    News::factory()->create(['slug' => 'soon', 'published_at' => now()->addDay()]);
    News::factory()->create(['slug' => 'undated', 'published_at' => null]);

    expect(News::query()->published()->pluck('slug')->sort()->values()->all())->toBe(['opening', 'undated']);
});

it('puts undated news among dated ones by the day they were created', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['news']])->assertSuccessful();
    bootCopiedNews($this);

    News::factory()->create(['slug' => 'old', 'published_at' => now()->subDays(5)]);
    News::factory()->create(['slug' => 'undated', 'published_at' => null, 'created_at' => now()->subDay()]);
    News::factory()->create(['slug' => 'fresh', 'published_at' => now()->subHour()]);

    expect(News::query()->published()->pluck('slug')->all())->toBe(['fresh', 'undated', 'old']);
});

it('gives the administrator a working news section that passes fastleo:doctor and shows the widget', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['news']])->assertSuccessful();
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
    $this->artisan('fastleo:module', ['modules' => ['news']])->assertSuccessful();
    bootCopiedNews($this);
    Fastleo::resources()->flush();
    $this->actingAs(admin(), Fastleo::GUARD);
    News::factory()->create(['title' => 'Старая без даты', 'published_at' => null, 'created_at' => now()->subDays(3)]);
    News::factory()->create(['title' => 'Свежая с датой', 'published_at' => now()->subDays(10), 'created_at' => now()]);
    News::factory()->create(['title' => 'Вчерашняя без даты', 'published_at' => null, 'created_at' => now()->subDay()]);

    $this->get(route('fastleo.resources.index', 'news'))->assertOk()->assertSeeInOrder(['Свежая с датой', 'Вчерашняя без даты', 'Старая без даты']);
});
