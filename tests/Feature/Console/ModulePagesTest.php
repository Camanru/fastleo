<?php

declare(strict_types=1);

use App\FastleoPages\Models\Page;
use Fastleo\Fastleo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    // Пишем в временную папку: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-pages-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/routes');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
    File::put($this->sandbox.'/composer.json', '{"autoload": {"psr-4": {"App\\\\FastleoPages\\\\": "app/"}}}');
    sandboxWithPest($this->sandbox);
    $this->app->setBasePath($this->sandbox);
    Schema::dropIfExists('pages'); // таблица демо-сайта: модуль не ставится поверх чужой
    config(['fastleo.resources.path' => $this->sandbox.'/app/Fastleo', 'fastleo.resources.namespace' => 'App\\FastleoPages']);
});

afterEach(function (): void {
    Model::shouldBeStrict(false);
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
        'app/Models/Page.php' => 'App\\FastleoPages\\Models\\Page',
        'app/Fastleo/PageResource.php' => 'App\\FastleoPages\\PageResource',
        'database/factories/PageFactory.php' => 'Database\\Factories\\PageFactory',
    ];

    foreach ($classes as $file => $class) {
        if (! class_exists($class, false)) {
            require $test->sandbox.'/'.$file;
        }
    }
}

/**
 * Поднять и публичную часть модуля: контроллер, запасной маршрут и шаблоны. Маршруты демо-сайта убираются: они занимают `/`.
 */
function bootCopiedPagesSite(object $test): void
{
    bootCopiedPages($test);
    forgetDemoRoutes();
    bootSiteRoutes($test, ['App\\FastleoPages\\Http\\Controllers\\PageController' => 'app/Http/Controllers/PageController.php'], ['routes/web.php']);
}

/**
 * Свежий `routes/web.php` с примером главной страницы нового Laravel.
 */
function welcomeSite(object $test): void
{
    File::put($test->sandbox.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/', function () {\n    return view('welcome');\n});\n");
}

it('copies the pages module with its public part and connects the fallback route', function (): void {
    welcomeSite($this);

    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])
        ->expectsOutputToContain('app/Models/Page.php')
        ->expectsOutputToContain('убран пример главной страницы Laravel')
        ->assertSuccessful();

    expect($this->sandbox.'/app/Models/Page.php')->toBeFile()
        ->and($this->sandbox.'/app/Fastleo/PageResource.php')->toBeFile()
        ->and(File::get($this->sandbox.'/app/Models/Page.php'))->toContain('namespace App\FastleoPages\Models;')
        ->and(glob($this->sandbox.'/database/migrations/*_create_pages_table.php'))->toHaveCount(1)
        ->and($this->sandbox.'/database/seeders/PageSeeder.php')->toBeFile()
        ->and($this->sandbox.'/app/Http/Controllers/PageController.php')->toBeFile()
        ->and($this->sandbox.'/resources/views/pages/show.blade.php')->toBeFile()
        ->and($this->sandbox.'/resources/views/blocks/cta.blade.php')->toBeFile()
        ->and(File::get($this->sandbox.'/routes/web.php'))->toContain('Route::fallback')->not->toContain("view('welcome')")->not->toContain('fastleo');
});

it('keeps the fallback route of the pages last whether the other modules come in the same command or later', function (): void {
    forgetRoute('contacts');

    $this->artisan('fastleo:module', ['modules' => ['pages', 'news'], '--no-interaction' => true])->assertSuccessful();

    $routes = siteRoutesOf($this);
    expect(strpos($routes, 'NewsController'))->toBeLessThan(strpos($routes, 'Route::fallback'));

    $this->artisan('fastleo:module', ['modules' => ['faq'], '--no-interaction' => true])->assertSuccessful();

    $routes = siteRoutesOf($this);
    expect(strpos($routes, 'FaqController'))->toBeLessThan(strpos($routes, 'Route::fallback'))
        ->and(substr_count($routes, 'Route::fallback'))->toBe(1);
});

it('asks nothing and writes the panel part only when the site has its own fallback route', function (): void {
    Route::fallback(fn (): string => 'своя страница 404');

    $this->artisan('fastleo:module', ['modules' => ['pages']])
        ->expectsOutputToContain('Публичная часть модуля pages не добавлена')
        ->expectsOutputToContain('запасной маршрут')
        ->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo/PageResource.php')->toBeFile()
        ->and(File::get($this->sandbox.'/routes/web.php'))->not->toContain('PageController::class');
});

it('keeps the welcome route when the public part is declined', function (): void {
    welcomeSite($this);

    $this->artisan('fastleo:module', ['modules' => ['pages']])
        ->expectsConfirmation(siteQuestion(), 'no')
        ->assertSuccessful();

    expect(File::get($this->sandbox.'/routes/web.php'))->toContain("view('welcome')");
});

it('copies the tests of the module and of its public part with it', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();

    expect($this->sandbox.'/tests/Feature/Fastleo/PagesTest.php')->toBeFile()
        ->and($this->sandbox.'/tests/Feature/Fastleo/PagesSiteTest.php')->toBeFile();
});

it('refuses to install over an existing pages table', function (): void {
    Schema::create('pages', function ($table): void {
        $table->id();
    });

    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])
        ->expectsOutputToContain('таблица pages уже есть')
        ->assertFailed();

    expect($this->sandbox.'/app/Models/Page.php')->not->toBeFile();
});

it('stops and keeps the own model of the site when it is named like the model of the module', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/app/Models');
    File::put($this->sandbox.'/app/Models/Page.php', '<?php // моя модель');

    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])
        ->expectsOutputToContain('app/Models/Page.php уже есть')
        ->assertFailed();

    expect(File::get($this->sandbox.'/app/Models/Page.php'))->toBe('<?php // моя модель')
        ->and($this->sandbox.'/app/Fastleo/PageResource.php')->not->toBeFile()
        ->and($this->sandbox.'/database/seeders/PageSeeder.php')->not->toBeFile()
        ->and(File::glob($this->sandbox.'/database/migrations/*'))->toBe([]);
});

it('keeps the own controller of the site and skips only the public part of the module', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/app/Http/Controllers');
    File::put($this->sandbox.'/app/Http/Controllers/PageController.php', '<?php // мой контроллер');

    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])
        ->expectsOutputToContain('app/Http/Controllers/PageController.php уже есть')
        ->assertSuccessful();

    expect(File::get($this->sandbox.'/app/Http/Controllers/PageController.php'))->toBe('<?php // мой контроллер')
        ->and($this->sandbox.'/app/Models/Page.php')->toBeFile()
        ->and($this->sandbox.'/app/Fastleo/PageResource.php')->toBeFile()
        ->and($this->sandbox.'/resources/views/pages/show.blade.php')->not->toBeFile()
        ->and(File::get($this->sandbox.'/routes/web.php'))->not->toContain('PageController::class');
});

it('reminds to refresh the cache of the sections when the site keeps one', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/bootstrap/cache');
    File::put($this->sandbox.'/bootstrap/cache/fastleo.php', '<?php return [];');

    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])
        ->expectsOutputToContain('php artisan fastleo:cache')
        ->assertSuccessful();
});

it('finds a migration of the site also when the path of the site has glob characters', function (): void {
    $special = $this->sandbox.'/site [1]';
    File::ensureDirectoryExists($special.'/database/migrations');
    File::ensureDirectoryExists($special.'/routes');
    File::put($special.'/routes/web.php', "<?php\n");
    File::put($special.'/composer.json', File::get($this->sandbox.'/composer.json'));
    sandboxWithPest($special);
    File::put($special.'/database/migrations/2024_01_01_000000_create_pages_table.php', '<?php');
    $this->app->setBasePath($special);

    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])
        ->expectsOutputToContain('create_pages_table.php уже есть')
        ->assertFailed();
});

it('refuses to install over a migration of the site that was not run yet and creates the same table', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/database/migrations');
    File::put($this->sandbox.'/database/migrations/2024_01_01_000000_create_pages_table.php', '<?php');

    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])
        ->expectsOutputToContain('database/migrations/2024_01_01_000000_create_pages_table.php уже есть')
        ->assertFailed();

    expect($this->sandbox.'/app/Models/Page.php')->not->toBeFile()
        ->and(File::glob($this->sandbox.'/database/migrations/*'))->toHaveCount(1);
});

it('keeps the published pages and takes the sort column in strict mode', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedPages($this);
    Model::preventLazyLoading();
    Model::preventSilentlyDiscardingAttributes();

    Page::query()->create(['title' => 'Порядок', 'slug' => 'order', 'sort' => 5]);
    Page::factory()->create(['slug' => 'closed', 'is_published' => false]);

    expect(Page::query()->where('slug', 'order')->value('sort'))->toBe(5)
        ->and(Page::query()->published()->pluck('slug')->all())->toBe(['order']);
});

it('gives the administrator a working pages section that passes fastleo:doctor', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();
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

it('accepts only a path of the site or an http address in the link of the call block', function (string $url, bool $valid): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedPages($this);
    Fastleo::resources()->flush();
    $this->actingAs(admin(), Fastleo::GUARD);

    $response = $this->post(route('fastleo.resources.store', 'site-pages'), [
        'title' => 'Контакты', 'slug' => 'contacts', 'is_published' => '1',
        'content' => [['type' => 'cta', 'cta' => ['title' => 'Звоните', 'url' => $url]]],
    ]);

    $valid ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('content.0.cta.url');
    expect(Page::query()->count())->toBe($valid ? 1 : 0);
})->with([
    'path' => ['/contacts', true],
    'address' => ['https://example.com/x', true],
    'empty' => ['', true],
    'script' => ['javascript:alert(1)', false],
    'protocol relative' => ['//evil.example', false],
    'backslash' => ['/\\evil.example', false],
]);

it('shows the home page at the root and the pages by the addresses of their parents', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedPagesSite($this);
    Model::shouldBeStrict();

    Page::factory()->create(['title' => 'Добро пожаловать', 'slug' => 'home', 'is_home' => true]);
    $about = Page::factory()->create(['title' => 'О компании', 'slug' => 'about']);
    Page::factory()->create(['title' => 'Команда', 'slug' => 'team', 'parent_id' => $about->id]);

    $this->get('/')->assertOk()->assertSee('Добро пожаловать');
    $this->get('/about')->assertOk()->assertSee('О компании');
    $this->get('/about/team')->assertOk()->assertSee('Команда');
    $this->get('/team')->assertNotFound();
    $this->get('/home')->assertNotFound();
});

it('answers 404 for hidden pages, pages inside hidden pages and a site without a home page', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedPagesSite($this);
    $closed = Page::factory()->create(['slug' => 'closed', 'is_published' => false]);
    Page::factory()->create(['slug' => 'inner', 'parent_id' => $closed->id]);

    foreach (['/', '/closed', '/closed/inner', '/no-such-page'] as $url) {
        $this->get($url)->assertNotFound();
    }
});

it('answers 405 to a form sent to an address that no route of the site takes, as the docs promise', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedPagesSite($this);

    $this->post('/no-such-page')->assertStatus(405);
    $this->post('/')->assertStatus(405);
});

// Прямой вызов модели: на MySQL/MariaDB `where slug = 'ABOUT'` находит `about` (сортировка не различает регистр и пробелы
// на конце), и страницу отсекает только точное сравнение слага; на SQLite запрос и так не находит, тест зелёный в любом случае.
it('finds a page only by its exact slug, whatever the collation of the database says', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedPages($this);
    $about = Page::factory()->create(['slug' => 'about']);
    Page::factory()->create(['slug' => 'team', 'parent_id' => $about->id]);

    expect(Page::findByPath('about')?->is($about))->toBeTrue()
        ->and(Page::findByPath('ABOUT'))->toBeNull()
        ->and(Page::findByPath('about '))->toBeNull()
        ->and(Page::findByPath('about/team')?->slug)->toBe('team')
        ->and(Page::findByPath('about/TEAM'))->toBeNull();
});

it('does not ask the database about an address that cannot be a page', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedPagesSite($this);
    Page::factory()->create(['slug' => 'about']);
    DB::enableQueryLog();

    foreach (['/.env', '/wp-login.php', '/About', '/a%00b', '/about//team'] as $url) {
        $this->get($url)->assertNotFound();
    }

    expect(DB::getQueryLog())->toBe([]);
});

it('does not take the routes of the site: its own pages and its own home route win over the fallback', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedPagesSite($this);
    Route::get('/', fn (): string => 'своя главная');
    Route::get('/about', fn (): string => 'свой маршрут');
    Page::factory()->create(['title' => 'Главная модуля', 'slug' => 'home', 'is_home' => true]);
    Page::factory()->create(['title' => 'Страница модуля', 'slug' => 'about']);

    $this->get('/')->assertOk()->assertSee('своя главная')->assertDontSee('Главная модуля');
    $this->get('/about')->assertOk()->assertSee('свой маршрут');
});

it('shows the text, image, gallery and call blocks and puts a link without a safe address out of the call', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedPagesSite($this);
    Page::factory()->create(['slug' => 'blocks', 'content' => [
        ['type' => 'text', 'data' => ['body' => '<p>Текст блока</p>']],
        ['type' => 'image', 'data' => ['src' => 'uploads/pages/photo.jpg', 'caption' => 'Подпись <b>картинки</b>']],
        ['type' => 'gallery', 'data' => ['photos' => ['uploads/pages/a.jpg', 'uploads/pages/b.jpg']]],
        ['type' => 'cta', 'data' => ['title' => 'Позвоните', 'text' => 'Мы рядом', 'button' => 'Хорошо', 'url' => '/contacts']],
    ]]);

    $this->get('/blocks')->assertOk()
        ->assertSee('<p>Текст блока</p>', false)
        ->assertSee('uploads/pages/photo.jpg', false)
        ->assertSee('Подпись &lt;b&gt;картинки&lt;/b&gt;', false)
        ->assertSee('uploads/pages/a.jpg', false)
        ->assertSee('uploads/pages/b.jpg', false)
        ->assertSee('<a href="/contacts">Хорошо</a>', false);
});

it('skips a field of a block that is not a string instead of failing the page', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedPagesSite($this);
    Page::factory()->create(['slug' => 'odd', 'content' => [
        ['type' => 'text', 'data' => ['body' => ['не', 'строка']]],
        ['type' => 'cta', 'data' => ['title' => ['x'], 'text' => ['y'], 'button' => ['z'], 'url' => ['/a']]],
        ['type' => 'image', 'data' => ['src' => 'uploads/pages/photo.jpg', 'caption' => ['p']]],
        ['type' => 'text', 'data' => ['__env' => 'x', 'body' => '<p>после</p>']],
        ['type' => 'gallery', 'data' => ['__env' => 'x', 'photos' => ['uploads/pages/a.jpg']]],
    ]]);

    $this->get('/odd')->assertOk()
        ->assertSee('uploads/pages/photo.jpg', false)
        ->assertSee('<p>после</p>', false)
        ->assertSee('uploads/pages/a.jpg', false);
});

it('opens the home page at the root even when it has a parent page, and takes the first one when there are several', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedPagesSite($this);
    $section = Page::factory()->create(['slug' => 'section']);
    Page::factory()->create(['title' => 'Первая главная', 'slug' => 'start', 'is_home' => true, 'parent_id' => $section->id]);
    Page::factory()->create(['title' => 'Вторая главная', 'slug' => 'other', 'is_home' => true]);

    $this->get('/')->assertOk()->assertSee('Первая главная')->assertDontSee('Вторая главная');
});

it('does not read the blocks of the pages for the menu', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedPagesSite($this);
    Page::factory()->create(['slug' => 'about', 'show_in_menu' => true]);
    DB::enableQueryLog();

    $menu = Page::menu();

    expect($menu)->toHaveCount(1)
        ->and(DB::getQueryLog()[0]['query'])->not->toContain('content');
});

it('prints the link of a call block only when it is a path of the site or an http address', function (string $url, bool $shown): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedPagesSite($this);
    Page::factory()->create(['slug' => 'cta', 'content' => [['type' => 'cta', 'data' => ['title' => 'Призыв', 'button' => 'Кнопка', 'url' => $url]]]]);

    $response = $this->get('/cta')->assertOk()->assertSee('Призыв');

    $shown ? $response->assertSee('>Кнопка</a>', false) : $response->assertDontSee('>Кнопка</a>', false);
})->with([
    'path' => ['/contacts', true],
    'address' => ['https://example.com/x', true],
    'uppercase scheme' => ['HTTPS://example.com/x', true],
    'script' => ['javascript:alert(1)', false],
    'mixed case script' => ['JaVaScRiPt:alert(1)', false],
    'protocol relative' => ['//evil.example', false],
    'backslash' => ['/\\evil.example', false],
    'scheme without slashes' => ['http:evil.example', false],
    'leading space' => [' /contacts', false],
    'empty' => ['', false],
]);

it('escapes the title of a page', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedPagesSite($this);
    Page::factory()->create(['title' => '<script>alert(1)</script>', 'slug' => 'xss']);

    $this->get('/xss')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
});

it('shows the menu of pages in the header of the layout and the page address by its parents', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedPagesSite($this);
    Model::shouldBeStrict();
    $about = Page::factory()->create(['title' => 'О компании', 'slug' => 'about', 'show_in_menu' => true, 'sort' => 1]);
    Page::factory()->create(['title' => 'Команда', 'slug' => 'team', 'parent_id' => $about->id, 'show_in_menu' => true]);
    Page::factory()->create(['title' => 'Скрытая', 'slug' => 'hidden', 'show_in_menu' => true, 'is_published' => false]);
    Page::factory()->create(['title' => 'Главная страница', 'slug' => 'home', 'is_home' => true, 'show_in_menu' => true, 'sort' => 0]);

    $this->get('/about')->assertOk()
        ->assertSeeInOrder(['Главная страница', 'О компании'])
        ->assertDontSee('Скрытая')
        ->assertDontSee('>Команда</a>', false);

    expect(Page::query()->where('slug', 'team')->firstOrFail()->url())->toEndWith('/about/team')
        ->and(Page::query()->where('slug', 'home')->firstOrFail()->url())->toBe(url('/'));
});

it('stops the address of a deep page at ten parents', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['pages'], '--no-interaction' => true])->assertSuccessful();
    bootCopiedPagesSite($this);
    $parent = null;

    for ($level = 0; $level < 12; $level++) {
        $parent = Page::factory()->create(['slug' => 'level-'.$level, 'parent_id' => $parent?->id]);
    }

    expect(array_filter(explode('/', (string) parse_url($parent->url(), PHP_URL_PATH))))->toHaveCount(11);
});
