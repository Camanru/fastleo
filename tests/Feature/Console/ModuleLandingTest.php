<?php

declare(strict_types=1);

use App\FastleoLanding\LandingBlocks;
use App\FastleoLanding\Models\LandingPage;
use App\FastleoLanding\Probe\BoomController;
use Fastleo\Fastleo;
use Fastleo\Fields\Blocks;
use Fastleo\Leads\Lead;
use Fastleo\Leads\LeadController;
use Fastleo\Support\Icons;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function (): void {
    // Пишем в временную папку: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-landing-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/routes');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
    File::put($this->sandbox.'/composer.json', '{"autoload": {"psr-4": {"App\\\\FastleoLanding\\\\": "app/"}}}');
    sandboxWithPest($this->sandbox);
    $this->app->setBasePath($this->sandbox);
    Schema::dropIfExists('landing_pages');
    config(['fastleo.resources.path' => $this->sandbox.'/app/Fastleo', 'fastleo.resources.namespace' => 'App\\FastleoLanding']);
});

afterEach(function (): void {
    Model::shouldBeStrict(false);
    Factory::flushState();
    File::deleteDirectory($this->sandbox);
});

/**
 * Скопировать модуль «Лендинг» в песочницу и поднять его: таблица, классы (один раз на процесс), фабрика, публичная часть.
 */
function bootCopiedLanding(object $test, bool $site = true): void
{
    $test->artisan('fastleo:module', ['modules' => ['landing'], '--no-interaction' => true])->assertSuccessful();
    $test->artisan('migrate', ['--path' => $test->sandbox.'/database/migrations', '--realpath' => true])->assertSuccessful();

    $classes = [
        'app/Models/LandingPage.php' => 'App\\FastleoLanding\\Models\\LandingPage',
        'app/Fastleo/LandingBlocks.php' => 'App\\FastleoLanding\\LandingBlocks',
        'app/Fastleo/LandingPageResource.php' => 'App\\FastleoLanding\\LandingPageResource',
        'database/factories/LandingPageFactory.php' => 'Database\\Factories\\LandingPageFactory',
        'database/seeders/LandingPageSeeder.php' => 'Database\\Seeders\\LandingPageSeeder',
    ];

    foreach ($classes as $file => $class) {
        if (! class_exists($class, false)) {
            require $test->sandbox.'/'.$file;
        }
    }

    Fastleo::resources()->flush();

    if ($site) {
        // Маршруты демо-сайта занимают «/».
        forgetDemoRoutes();
        bootSiteRoutes($test, ['App\\FastleoLanding\\Http\\Controllers\\LandingController' => 'app/Http/Controllers/LandingController.php'], ['routes/web.php']);
    }
}

/**
 * Лендинг с заданными блоками.
 *
 * @param  list<array{type: string, data: array<string, mixed>}>  $content
 */
function landingOf(array $content, bool $published = true, string $url = '/'): LandingPage
{
    return LandingPage::query()->create(['title' => 'Студия', 'url' => $url, 'content' => $content, 'is_published' => $published]);
}

/**
 * Включить приём заявок: форма блока «Форма заявки» показывается, когда есть маршрут обработчика.
 */
function landingLeads(): void
{
    Route::middleware('web')->post('/leads', [LeadController::class, 'store'])->name('fastleo-leads.store');
    Route::getRoutes()->refreshNameLookups();
}

it('copies the module, replaces the welcome route and warns that the home page of the pages module will not open', function (): void {
    app()->setLocale('ru');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/', function () {\n    return view('welcome');\n});\n");

    $this->artisan('fastleo:module', ['modules' => ['landing'], '--no-interaction' => true])
        ->expectsOutputToContain('app/Models/LandingPage.php')
        ->expectsOutputToContain('главную теперь показывает модуль «Лендинг»')
        ->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo/LandingPageResource.php')->toBeFile()
        ->and($this->sandbox.'/app/Fastleo/LandingBlocks.php')->toBeFile()
        ->and(File::get($this->sandbox.'/app/Fastleo/LandingBlocks.php'))->toContain('namespace App\FastleoLanding;')
        ->and(glob($this->sandbox.'/database/migrations/*_create_landing_pages_table.php'))->toHaveCount(1)
        ->and($this->sandbox.'/app/Http/Controllers/LandingController.php')->toBeFile()
        ->and($this->sandbox.'/resources/views/landing/show.blade.php')->toBeFile()
        ->and(glob($this->sandbox.'/resources/views/landing/sections/*.blade.php'))->toHaveCount(19)
        ->and(File::get($this->sandbox.'/routes/web.php'))->toContain('site.landing.show')->not->toContain("view('welcome')");
});

it('writes the route of the landing below the routes of the modules installed with it', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['landing', 'news'], '--no-interaction' => true])->assertSuccessful();

    $routes = File::get($this->sandbox.'/routes/web.php');

    expect(strpos($routes, "'/news'"))->toBeLessThan(strpos($routes, 'site.landing.show'));
});

it('inserts the routes of a module installed later above the landing and does not warn about shadowing', function (): void {
    app()->setLocale('ru');

    $this->artisan('fastleo:module', ['modules' => ['landing'], '--no-interaction' => true])->assertSuccessful();

    // Маршрут лендинга уже загружен, как на работающем сайте: он подходит и под `/reviews`.
    Route::get('/{landing?}', fn (): string => 'landing')->where('landing', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('site.landing.show');
    Route::getRoutes()->refreshNameLookups();

    $this->artisan('fastleo:module', ['modules' => ['reviews'], '--no-interaction' => true])->doesntExpectOutputToContain('перекроет адрес')->assertSuccessful();

    $routes = File::get($this->sandbox.'/routes/web.php');

    expect(strpos($routes, "'/reviews'"))->toBeLessThan(strpos($routes, 'site.landing.show'));
});

it('says nothing about route files or the fallback route when the site has neither', function (): void {
    app()->setLocale('ru');

    $this->artisan('fastleo:module', ['modules' => ['landing'], '--no-interaction' => true])
        ->doesntExpectOutputToContain('из файлов, которые грузятся после него')
        ->doesntExpectOutputToContain('есть middleware')
        ->assertSuccessful();
});

it('warns that the landing closes the single-segment addresses of the route files that load after web.php', function (): void {
    app()->setLocale('ru');
    File::put($this->sandbox.'/routes/admin.php', "<?php\n");
    File::put($this->sandbox.'/routes/console.php', "<?php\n");
    File::ensureDirectoryExists($this->sandbox.'/bootstrap');
    File::put($this->sandbox.'/bootstrap/app.php', "<?php\n\nreturn Application::configure()->withRouting(web: __DIR__.'/../routes/web.php', then: function () {})->create();\n");

    $this->artisan('fastleo:module', ['modules' => ['landing'], '--no-interaction' => true])
        ->expectsOutputToContain('routes/admin.php, bootstrap/app.php (then:)')
        ->assertSuccessful();
});

it('does not count route files that web.php itself includes, nor a comment about then:', function (): void {
    app()->setLocale('ru');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nrequire __DIR__.'/auth.php';\n");
    File::put($this->sandbox.'/routes/auth.php', "<?php\n");
    File::put($this->sandbox.'/routes/admin.php', "<?php\n");
    File::ensureDirectoryExists($this->sandbox.'/bootstrap');
    File::put($this->sandbox.'/bootstrap/app.php', "<?php\n\n// then: closure for extra routes would go here\nreturn Application::configure()->withRouting(web: __DIR__.'/../routes/web.php')->create();\n");

    $this->artisan('fastleo:module', ['modules' => ['landing'], '--no-interaction' => true])
        ->expectsOutputToContain('(routes/admin.php)')
        ->doesntExpectOutputToContain('auth.php')
        ->doesntExpectOutputToContain('(then:)')
        ->assertSuccessful();
});

it('does not build the controller of the fallback route of the site to read its middleware, so a failing constructor cannot break the install', function (): void {
    app()->setLocale('ru');
    eval('namespace App\\FastleoLanding\\Probe; class BoomController extends \\Illuminate\\Routing\\Controller { public function __construct() { throw new \\RuntimeException("конструктор упал"); } public function show(): string { return "x"; } }');
    Route::fallback([BoomController::class, 'show']);

    $this->artisan('fastleo:module', ['modules' => ['landing'], '--no-interaction' => true])->assertSuccessful();

    expect($this->sandbox.'/app/Http/Controllers/LandingController.php')->toBeFile()
        ->and(File::get($this->sandbox.'/routes/web.php'))->toContain('site.landing.show');
});

it('warns that the fallback route of the site loses its middleware under the landing', function (): void {
    app()->setLocale('ru');
    Route::fallback(fn (): string => 'x')->middleware(['web', 'auth', 'can:view-site']);

    $this->artisan('fastleo:module', ['modules' => ['landing'], '--no-interaction' => true])
        ->expectsOutputToContain('есть middleware «auth», «can:view-site»')
        ->assertSuccessful();
});

it('does not warn about a fallback route that has only the web group', function (): void {
    app()->setLocale('ru');
    Route::fallback(fn (): string => 'x')->middleware('web');

    $this->artisan('fastleo:module', ['modules' => ['landing'], '--no-interaction' => true])
        ->doesntExpectOutputToContain('есть middleware')
        ->assertSuccessful();
});

it('has one section of the panel that passes the doctor without the warning about the number of fields of the form', function (): void {
    bootCopiedLanding($this, site: false);
    $this->actingAs(admin(), Fastleo::GUARD);

    Artisan::call('fastleo:doctor', ['--json' => true]);
    $problems = collect(json_decode(Artisan::output(), true)['problems'])->where('scope', 'landing');

    expect($problems->all())->toBe([]);
});

it('lets the administrator create landings with their own addresses', function (): void {
    bootCopiedLanding($this, site: false);
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->post(route('fastleo.resources.store', 'landing'), ['title' => 'Первый', 'url' => '/', 'is_published' => '1'])->assertSessionHasNoErrors()->assertRedirect();
    $this->post(route('fastleo.resources.store', 'landing'), ['title' => 'Второй', 'url' => '/promo', 'is_published' => '1'])->assertSessionHasNoErrors()->assertRedirect();
    $this->get(route('fastleo.resources.index', 'landing'))->assertOk()->assertSee('Первый')->assertSee('/promo');

    expect(LandingPage::query()->orderBy('id')->pluck('url')->all())->toBe(['/', '/promo']);
});

it('refuses a repeated or malformed address of a landing', function (): void {
    bootCopiedLanding($this, site: false);
    $this->actingAs(admin(), Fastleo::GUARD);

    landingOf([], url: '/promo');

    foreach (['/promo', 'promo', '/Promo', '/PROMO', '/a/b', '/a b', '//evil.test', '/promo/', '/промо', '/a%2Fb', '/promo.', ''] as $url) {
        $this->post(route('fastleo.resources.store', 'landing'), ['title' => 'Новый', 'url' => $url, 'is_published' => '1'])->assertSessionHasErrors('url');
    }

    expect(LandingPage::query()->count())->toBe(1);

    $record = LandingPage::query()->sole();

    $this->put(route('fastleo.resources.update', ['landing', $record->getKey()]), ['title' => 'Новое название', 'url' => '/promo', 'is_published' => '1'])->assertSessionHasNoErrors();

    expect($record->refresh()->title)->toBe('Новое название');
});

it('refuses an address that another section, a page or a folder of the site already answers', function (): void {
    bootCopiedLanding($this, site: false);
    $this->actingAs(admin(), Fastleo::GUARD);

    // Панель и маршрут лендинга вместе: у публичной части сайта в песочнице маршруты панели не живут.
    Route::get('/shop', fn (): string => 'shop');
    Route::get('/{landing?}', fn (): string => 'landing')->where('landing', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('site.landing.show');

    // Корневая страница модуля «Страницы»: класс и таблица с теми же колонками (таблица `pages` демо-сайта пакета занята).
    // Класс после теста остаётся в процессе (eval не выгрузить); без своей таблицы он проверку адреса не ломает: она под `rescue`.
    if (! class_exists('App\\FastleoLanding\\Models\\Page', false)) {
        eval('namespace App\\FastleoLanding\\Models; class Page extends \\Illuminate\\Database\\Eloquent\\Model { protected $table = \'landing_test_pages\'; protected $guarded = []; public $timestamps = false; }');
    }

    Schema::create('landing_test_pages', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->string('slug');
        $table->boolean('is_home')->default(false);
    });
    DB::table('landing_test_pages')->insert([['slug' => 'about', 'parent_id' => null, 'is_home' => false], ['slug' => 'team', 'parent_id' => 1, 'is_home' => false], ['slug' => 'home', 'parent_id' => null, 'is_home' => true]]);

    File::ensureDirectoryExists(public_path('landing-test-dir'));

    try {
        foreach (['/shop', '/fastleo', '/about', '/landing-test-dir'] as $url) {
            $this->post(route('fastleo.resources.store', 'landing'), ['title' => 'Новый', 'url' => $url, 'is_published' => '1'])->assertSessionHasErrors('url');
        }

        // Вложенная страница другой страницы не мешает: у неё нет адреса из одного сегмента.
        // Главная «Страниц» живёт на «/», её слаг адрес не занимает.
        $this->post(route('fastleo.resources.store', 'landing'), ['title' => 'Команда', 'url' => '/team', 'is_published' => '1'])->assertSessionHasNoErrors();
        $this->post(route('fastleo.resources.store', 'landing'), ['title' => 'Домой', 'url' => '/home', 'is_published' => '1'])->assertSessionHasNoErrors();
        $this->post(route('fastleo.resources.store', 'landing'), ['title' => 'Новый', 'url' => '/free', 'is_published' => '1'])->assertSessionHasNoErrors();
        $this->post(route('fastleo.resources.store', 'landing'), ['title' => 'Главная', 'url' => '/', 'is_published' => '1'])->assertSessionHasNoErrors();
    } finally {
        File::deleteDirectory(public_path('landing-test-dir'));
        Schema::dropIfExists('landing_test_pages');
    }

    expect(LandingPage::query()->orderBy('id')->pluck('url')->all())->toBe(['/team', '/home', '/free', '/']);
});

it('tells only about the format of a malformed address and not about a clash', function (): void {
    bootCopiedLanding($this, site: false);
    $this->actingAs(admin(), Fastleo::GUARD);

    Route::get('/{landing?}', fn (): string => 'landing')->name('site.landing.show');

    $this->post(route('fastleo.resources.store', 'landing'), ['title' => 'Новый', 'url' => '/a b', 'is_published' => '1'])->assertSessionHasErrors('url');

    expect(session('errors')->get('url'))->toHaveCount(1);
});

it('keeps the addresses of the panel for the panel even when the site has no public part of the landing', function (): void {
    bootCopiedLanding($this, site: false);
    $this->actingAs(admin(), Fastleo::GUARD);

    // Панель на корне домена: её маршруты называются fastleo.*, маршрута лендинга у сайта нет.
    Route::get('/', fn (): string => 'dashboard')->name('fastleo.dashboard-test');
    Route::get('/login', fn (): string => 'login')->name('fastleo.login-test');
    Route::get('/pricing', fn (): string => 'своя страница сайта')->name('site.own');

    foreach (['/', '/login'] as $url) {
        $this->post(route('fastleo.resources.store', 'landing'), ['title' => 'Новый', 'url' => $url, 'is_published' => '1'])->assertSessionHasErrors('url');
    }

    // Свои маршруты сайта без публичной части лендинга — дело сайта.
    $this->post(route('fastleo.resources.store', 'landing'), ['title' => 'Новый', 'url' => '/pricing', 'is_published' => '1'])->assertSessionHasNoErrors();
});

it('does not check again the address that a landing already has when the site gets a route to it later', function (): void {
    bootCopiedLanding($this, site: false);
    $this->actingAs(admin(), Fastleo::GUARD);

    $landing = landingOf([], url: '/shop');

    Route::get('/shop', fn (): string => 'shop');
    Route::get('/{landing?}', fn (): string => 'landing')->where('landing', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('site.landing.show');

    $this->put(route('fastleo.resources.update', ['landing', $landing->getKey()]), ['title' => 'Новое название', 'url' => '/shop', 'is_published' => '1'])->assertSessionHasNoErrors();
    $this->put(route('fastleo.resources.update', ['landing', $landing->getKey()]), ['title' => 'Новое название', 'url' => '/shop-two', 'is_published' => '1'])->assertSessionHasNoErrors();

    expect($landing->refresh()->url)->toBe('/shop-two');
});

it('saves the blocks of every type through the form with the fields of the type', function (): void {
    bootCopiedLanding($this, site: false);
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->post(route('fastleo.resources.store', 'landing'), ['title' => 'Студия', 'url' => '/', 'is_published' => '1', 'content' => [
        'a' => ['type' => 'hero', 'hero' => ['title' => 'Сайты', 'primary_label' => 'Заказать', 'primary_url' => 'javascript:alert(1)', 'tone' => 'light', 'is_hidden' => '0']],
    ]])->assertSessionHasErrors('content.a.hero.primary_url');

    $this->post(route('fastleo.resources.store', 'landing'), ['title' => 'Студия', 'url' => '/', 'is_published' => '1', 'content' => [
        'a' => ['type' => 'hero', 'hero' => ['title' => 'Сайты', 'primary_label' => 'Заказать', 'primary_url' => '#pricing', 'anchor' => 'Bad Anchor', 'tone' => 'light', 'is_hidden' => '0']],
    ]])->assertSessionHasErrors('content.a.hero.anchor');

    $this->post(route('fastleo.resources.store', 'landing'), ['title' => 'Студия', 'url' => '/', 'is_published' => '1', 'content' => [
        'a' => ['type' => 'hero', 'hero' => ['title' => 'Сайты', 'primary_label' => 'Заказать', 'primary_url' => '#pricing', 'anchor' => 'top', 'tone' => 'light', 'is_hidden' => '0']],
        'b' => ['type' => 'benefits', 'benefits' => ['title' => 'Плюсы', 'rows' => [['icon' => 'zap', 'title' => 'Быстро']]]],
    ]])->assertSessionHasNoErrors();

    $content = LandingPage::query()->sole()->content;

    expect(collect($content)->pluck('type')->all())->toBe(['hero', 'benefits'])
        ->and($content[0]['data']['primary_url'])->toBe('#pricing')
        ->and($content[1]['data']['rows'][0]['icon'])->toBe('zap');
});

it('refuses a button link that is an anchor in capitals or with a space, and takes the scheme in any case', function (): void {
    bootCopiedLanding($this, site: false);
    $this->actingAs(admin(), Fastleo::GUARD);
    $post = fn (string $url) => $this->post(route('fastleo.resources.store', 'landing'), ['title' => 'Студия', 'url' => '/', 'is_published' => '1', 'content' => [
        'a' => ['type' => 'hero', 'hero' => ['title' => 'Сайты', 'primary_label' => 'Заказать', 'primary_url' => $url, 'tone' => 'light', 'is_hidden' => '0']],
    ]]);

    foreach (['#Pricing', '#a b', '#', 'https://', 'java script:x'] as $url) {
        $post($url)->assertSessionHasErrors('content.a.hero.primary_url');
    }

    $post('HTTPS://example.com/x')->assertSessionHasNoErrors();
});

it('refuses an icon that is not in the list of the benefits block', function (): void {
    bootCopiedLanding($this, site: false);
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->post(route('fastleo.resources.store', 'landing'), ['title' => 'Студия', 'url' => '/', 'is_published' => '1', 'content' => [
        'b' => ['type' => 'benefits', 'benefits' => ['rows' => [['icon' => '../../etc', 'title' => 'Быстро']]]],
    ]])->assertSessionHasErrors();

    expect(LandingPage::query()->count())->toBe(0);
});

it('has only icons that exist in the set of the package', function (): void {
    bootCopiedLanding($this, site: false);
    $icons = new ReflectionClassConstant(LandingBlocks::class, 'ICONS')->getValue();

    expect($icons)->not->toBeEmpty();

    foreach (array_keys($icons) as $icon) {
        expect(Icons::exists($icon))->toBeTrue($icon);
    }
});

it('gives the same blocks different fields: no block shares a field object with another', function (): void {
    bootCopiedLanding($this, site: false);

    $first = LandingBlocks::all();
    $second = LandingBlocks::all();

    expect($first)->toHaveCount(19)
        ->and(collect($first)->pluck('type')->all())->toBe(['header', 'hero', 'text', 'about', 'benefits', 'steps', 'numbers', 'highlight', 'pricing', 'gallery', 'reviews', 'team', 'logos', 'faq', 'video', 'cta', 'form', 'contacts', 'footer'])
        ->and(collect($first)->map(fn (object $block, int $index): bool => $block === $second[$index])->contains(true))->toBeFalse();

    // Ни одно поле ни одного из 19 блоков не общее: префикс правил записывается в поле, общий объект сломал бы правила соседнего блока.
    $firstFields = Blocks::make('a')->blocks($first);
    $secondFields = Blocks::make('b')->blocks($second);

    foreach ($first as $block) {
        foreach ($firstFields->schema($block->type)->fields() as $index => $field) {
            expect($field)->not->toBe($secondFields->schema($block->type)->fields()[$index]);
        }
    }
});

it('opens the landing at the root and answers 404 without a published landing', function (): void {
    bootCopiedLanding($this);

    $this->get('/')->assertNotFound();

    $landing = landingOf([['type' => 'text', 'data' => ['title' => 'О нас', 'body' => '<p>Текст</p>']]], published: false);

    $this->get('/')->assertNotFound();

    $landing->update(['is_published' => true]);

    $this->get('/')->assertOk()->assertSee('max-width: 100%', false)->assertSee('О нас')->assertSee('<p>Текст</p>', false)->assertSee('<title>Студия</title>', false);
});

it('opens a landing at its own address and leaves the other addresses to the fallback route of the site', function (): void {
    bootCopiedLanding($this);

    landingOf([['type' => 'text', 'data' => ['title' => 'Акционный']]], url: '/promo');
    landingOf([['type' => 'text', 'data' => ['title' => 'Закрытый']]], published: false, url: '/closed');

    $this->get('/promo')->assertOk()->assertSee('Акционный');
    $this->get('/pr%6Fmo')->assertOk()->assertSee('Акционный');
    $this->get('/closed')->assertNotFound();
    $this->get('/missing')->assertNotFound();
    $this->get('/')->assertNotFound();

    Route::fallback(fn (): string => 'from the fallback route');

    $this->get('/missing')->assertOk()->assertSee('from the fallback route');
    $this->get('/closed')->assertOk()->assertSee('from the fallback route');
    $this->get('/')->assertOk()->assertSee('from the fallback route');
    $this->get('/promo')->assertOk()->assertSee('Акционный')->assertDontSee('from the fallback route');
    $this->get('/a/b')->assertOk()->assertSee('from the fallback route');
});

it('answers 404 for addresses that only look like the address of a landing', function (): void {
    bootCopiedLanding($this);
    landingOf([['type' => 'text', 'data' => ['title' => 'Акционный']]], url: '/promo');

    $uris = ['/PROMO', '/Promo', '/a%2Fb', '/promo%2F', '/%D0%BF%D1%80%D0%BE%D0%BC%D0%BE', '/promo.', '/./promo', '/promo%00', '/promo%0A', '/index.php'];

    // Сравнивается весь набор: при падении видно, какой адрес ответил не 404.
    expect(array_map(fn (string $uri): int => $this->app->handle(Request::create($uri))->getStatusCode(), array_combine($uris, $uris)))->toBe(array_fill_keys($uris, 404));
});

it('keeps the link of a button with a topic on the page of its landing', function (): void {
    bootCopiedLanding($this);
    landingLeads();

    landingOf([
        ['type' => 'pricing', 'data' => ['plans' => [['name' => 'Бизнес', 'price' => '10', 'btn_label' => 'Заказать', 'btn_topic' => 'Тариф Бизнес']]]],
        ['type' => 'form', 'data' => []],
    ], url: '/promo');

    $this->get('/promo')->assertOk()->assertSee('/promo?topic=%D0%A2%D0%B0%D1%80%D0%B8%D1%84%20%D0%91%D0%B8%D0%B7%D0%BD%D0%B5%D1%81#form', false);
});

it('shows the seeded example and does not seed a second landing on the same address', function (): void {
    bootCopiedLanding($this);

    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\LandingPageSeeder', '--no-interaction' => true])->assertSuccessful();
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\LandingPageSeeder', '--no-interaction' => true])->assertSuccessful();

    expect(LandingPage::query()->count())->toBe(1);

    Model::shouldBeStrict();

    $this->get('/')->assertOk()
        ->assertSee('Сайт, который приводит заявки.')
        ->assertSee('id="pricing"', false)
        ->assertSee('href="#pricing"', false)
        ->assertSee('Старт')
        ->assertSee('landing-plan--featured', false)
        ->assertSee('Частые вопросы');
});

it('draws every type of block', function (): void {
    bootCopiedLanding($this);
    Model::shouldBeStrict();
    landingLeads();

    landingOf([
        ['type' => 'header', 'data' => ['brand' => 'Север', 'phone' => '+7 (900) 000-00-00', 'primary_label' => 'Заказать']],
        ['type' => 'hero', 'data' => ['eyebrow' => 'Надпись', 'title' => 'Заголовок первого экрана', 'subtitle' => "Первая строка\nвторая", 'accent' => 'Акцент', 'bullets' => [['text' => 'Пункт один']], 'primary_label' => 'Основная', 'second_label' => 'Вторая', 'second_url' => '/about', 'note' => 'Мелкий текст', 'image' => 'uploads/landing/hero.jpg']],
        ['type' => 'text', 'data' => ['title' => 'Текстовый блок', 'body' => '<p>Абзац</p>']],
        ['type' => 'about', 'data' => ['title' => 'О компании', 'body' => '<p>О нас</p>', 'image' => 'uploads/landing/about.jpg', 'image_position' => 'left']],
        ['type' => 'benefits', 'data' => ['title' => 'Плюсы', 'rows' => [['icon' => 'zap', 'title' => 'Быстро', 'text' => 'Очень', 'btn_label' => 'Хочу']], 'outro' => 'Итог под карточками']],
        ['type' => 'steps', 'data' => ['title' => 'Этапы', 'rows' => [['title' => 'Заявка', 'text' => 'Звоните']], 'primary_label' => 'Начать']],
        ['type' => 'numbers', 'data' => ['title' => 'Факты', 'rows' => [['value' => '12 лет', 'label' => 'на рынке']]]],
        ['type' => 'highlight', 'data' => ['eyebrow' => 'Гарантия', 'value' => '99,9 %', 'title' => 'Столько работает сайт.', 'muted' => 'Иначе месяц бесплатно.', 'note' => 'Примечание', 'rows' => [['title' => 'Следим', 'text' => 'Круглосуточно']]]],
        ['type' => 'pricing', 'data' => ['title' => 'Тарифы', 'plans' => [['name' => 'Бизнес', 'price' => 'от 5 000 ₽', 'features' => "Первое\n\nВторое", 'featured' => true, 'btn_label' => 'Выбрать']]]],
        ['type' => 'gallery', 'data' => ['title' => 'Работы', 'photos' => ['uploads/landing/one.jpg', 'uploads/landing/two.jpg']]],
        ['type' => 'reviews', 'data' => ['title' => 'Отзывы', 'rows' => [['author' => 'Анна', 'role' => 'Москва', 'text' => 'Отлично', 'rating' => 4]]]],
        ['type' => 'team', 'data' => ['title' => 'Команда', 'rows' => [['name' => 'Пётр', 'role' => 'Дизайнер']]]],
        ['type' => 'logos', 'data' => ['title' => 'Клиенты', 'logos' => ['uploads/landing/logo.png']]],
        ['type' => 'faq', 'data' => ['title' => 'Вопросы', 'rows' => [['question' => 'Сколько стоит?', 'answer' => "От пяти тысяч.\nТочнее скажем после звонка."]]]],
        ['type' => 'video', 'data' => ['title' => 'Видео', 'video' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'caption' => 'Подпись видео']],
        ['type' => 'cta', 'data' => ['title' => 'Готовы?', 'primary_label' => 'Позвоните', 'primary_url' => 'https://example.com/call']],
        ['type' => 'form', 'data' => ['title' => 'Заявка', 'text' => 'Перезвоним', 'button' => 'Отправить заявку', 'consent' => 'Согласие на обработку', 'consent_url' => '/privacy']],
        ['type' => 'contacts', 'data' => ['title' => 'Контакты', 'phone' => '+7 343 000-00-00', 'email' => 'info@example.com', 'address' => 'Екатеринбург, ул. Ленина, 1', 'hours' => "Пн–Пт 9–18\nСб выходной"]],
        ['type' => 'footer', 'data' => ['copyright' => '© Студия', 'links' => [['title' => 'Цены', 'url' => '#pricing']], 'policy_title' => 'Политика', 'policy_url' => '/privacy']],
    ]);

    $this->get('/')->assertOk()
        ->assertSeeInOrder(['Север', 'tel:+79000000000', 'Заказать', 'Надпись', 'Заголовок первого экрана', 'Первая строка<br />', 'Акцент', 'Пункт один', 'Основная', 'Вторая', 'Мелкий текст', 'hero.jpg'], false)
        ->assertSeeInOrder(['Текстовый блок', '<p>Абзац</p>', 'О компании', 'about.jpg', 'Плюсы', 'Быстро', 'Хочу', 'Итог под карточками', 'Этапы', 'Заявка', 'Начать', 'Факты', '12 лет', 'на рынке'], false)
        ->assertSeeInOrder(['99,9 %', 'Столько работает сайт.', 'Иначе месяц бесплатно.', 'Следим', 'Тарифы', 'Бизнес', 'от 5 000 ₽', 'Первое', 'Второе', 'Выбрать', 'one.jpg', 'two.jpg'], false)
        ->assertSeeInOrder(['Отзывы', 'Оценка 4 из 5', 'Отлично', 'Анна', 'Москва', 'Команда', 'Пётр', 'Дизайнер', 'logo.png', 'Вопросы', 'Сколько стоит?', 'От пяти тысяч.<br />'], false)
        ->assertSeeInOrder(['embed/dQw4w9WgXcQ', 'Подпись видео', 'https://example.com/call', 'Позвоните', 'Отправить заявку', 'Согласие на обработку', 'href="/privacy"'], false)
        ->assertSeeInOrder(['tel:+73430000000', 'mailto:info@example.com', 'Екатеринбург, ул. Ленина, 1', 'Пн–Пт 9–18<br />', 'yandex.ru/maps/?text=', '© Студия', 'Политика'], false)
        ->assertSee('landing-plan--featured', false)
        ->assertSee('id="form-form"', false);
});

it('gives decorative pictures an empty alt so that the title and the brand are not read twice', function (): void {
    bootCopiedLanding($this);

    landingOf([
        ['type' => 'header', 'data' => ['brand' => 'Север', 'logo' => 'uploads/landing/logo.png']],
        ['type' => 'hero', 'data' => ['title' => 'Заголовок', 'image' => 'uploads/landing/hero.png']],
        ['type' => 'about', 'data' => ['title' => 'О студии', 'body' => '<p>Текст</p>', 'image' => 'uploads/landing/about.png']],
    ]);

    $html = (string) $this->get('/')->assertOk()->getContent();

    // Каждая из трёх картинок — со своим пустым alt (а не просто три пустых alt где-то на странице).
    foreach (['logo.png', 'hero.png', 'about.png'] as $picture) {
        expect(preg_match('~<img\b[^>]*'.preg_quote($picture, '~').'[^>]*>~', $html, $tag))->toBe(1)
            ->and($tag[0])->toContain('alt=""');
    }
});

it('keeps unsafe values of the fields out of the page', function (): void {
    bootCopiedLanding($this);
    landingLeads();

    landingOf([
        ['type' => 'header', 'data' => ['brand' => '<script>alert(1)</script>', 'phone' => '123', 'primary_label' => 'Ссылка', 'primary_url' => 'javascript:alert(1)']],
        ['type' => 'hero', 'data' => ['title' => '<img src=x onerror=alert(2)>', 'primary_label' => 'Протокол', 'primary_url' => '//evil.example', 'second_label' => 'Скрипт', 'second_url' => 'JaVaScRiPt:alert(3)', 'tone' => '" onmouseover="alert(4)', 'anchor' => 'a" onclick="x']],
        ['type' => 'benefits', 'data' => ['rows' => [['icon' => '../../../etc/passwd', 'title' => '<b>Жирный</b>']]]],
        ['type' => 'contacts', 'data' => ['email' => 'x?bcc=a@example.com', 'phone' => '12', 'latitude' => '999', 'longitude' => '999', 'address' => 'Адрес <i>']],
        ['type' => 'footer', 'data' => ['links' => [['title' => 'Чужая', 'url' => 'javascript:alert(5)'], ['title' => 'Своя', 'url' => '/about']], 'policy_title' => 'Политика', 'policy_url' => 'data:text/html,x']],
    ]);

    $html = (string) $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert')
        ->not->toContain('<img src=x')
        ->not->toContain('javascript:')
        ->not->toContain('JaVaScRiPt')
        ->not->toContain('//evil.example')
        ->not->toContain('onmouseover')
        ->not->toContain('onclick')
        ->not->toContain('<b>Жирный</b>')
        ->not->toContain('../../etc')
        ->not->toContain('mailto:')
        ->not->toContain('tel:')
        ->not->toContain('data:text')
        ->not->toContain('pt=999')
        ->toContain('class="landing-section landing-section--hero landing-tone-light"')
        ->toContain('&lt;b&gt;Жирный&lt;/b&gt;')
        ->toContain('href="/about"')
        ->toContain('yandex.ru/maps/?text=%D0%90');
});

it('normalizes the blocks: hidden ones, a second header or footer, repeating and broken anchors and menu items', function (): void {
    bootCopiedLanding($this, site: false);

    $landing = landingOf([
        'мусор',
        ['type' => 'text'],
        ['type' => 'header', 'data' => ['brand' => 'Первая шапка']],
        ['type' => 'header', 'data' => ['brand' => 'Вторая шапка']],
        ['type' => 'text', 'data' => ['anchor' => 'about', 'menu_title' => ' О нас ', 'title' => 'Первый']],
        ['type' => 'text', 'data' => ['anchor' => 'about', 'menu_title' => 'Повтор', 'title' => 'Второй']],
        ['type' => 'text', 'data' => ['anchor' => 'Bad Anchor', 'menu_title' => 'Мусорный', 'tone' => 'red']],
        ['type' => 'text', 'data' => ['anchor' => 'secret', 'menu_title' => 'Скрытый', 'is_hidden' => true]],
        ['type' => 'text', 'data' => ['anchor' => 'faq', 'menu_title' => ['не', 'строка']]],
        ['type' => 'form', 'data' => ['title' => 'Первая форма']],
        ['type' => 'form', 'data' => ['title' => 'Вторая форма']],
        ['type' => 'footer', 'data' => ['copyright' => 'Первый']],
        ['type' => 'footer', 'data' => ['copyright' => 'Второй']],
    ]);

    $blocks = $landing->visibleBlocks();
    $anchors = array_column(array_column($blocks, 'data'), 'anchor');

    expect(collect($blocks)->pluck('type')->all())->toBe(['header', 'text', 'text', 'text', 'text', 'form', 'form', 'footer'])
        ->and($anchors)->toBe(['', 'about', '', '', 'faq', 'form', 'form-2', ''])
        ->and($blocks[3]['data']['tone'])->toBe('light')
        ->and($landing->menu())->toBe([['title' => 'О нас', 'anchor' => 'about']])
        ->and($landing->formAnchor())->toBe('form');
});

it('leads the buttons without a link to the form with the topic, and a button without a form nowhere', function (): void {
    bootCopiedLanding($this);
    landingLeads();

    landingOf([
        ['type' => 'pricing', 'data' => ['plans' => [['name' => 'Бизнес', 'price' => '5 000 ₽', 'btn_label' => 'Выбрать'], ['name' => 'Старт', 'btn_label' => 'Заказать', 'btn_topic' => 'Свой тема & вопрос']]]],
        ['type' => 'cta', 'data' => ['title' => 'Позвоните', 'primary_label' => 'К разделу', 'primary_url' => '#pricing', 'primary_topic' => 'Цены']],
        ['type' => 'form', 'data' => ['title' => 'Заявка']],
    ]);

    $this->get('/')->assertOk()
        ->assertSee('href="'.url('/').'/?topic=%D0%A2%D0%B0%D1%80%D0%B8%D1%84%20%C2%AB%D0%91%D0%B8%D0%B7%D0%BD%D0%B5%D1%81%C2%BB#form"', false)
        ->assertSee('?topic='.rawurlencode('Свой тема & вопрос').'#form', false)
        ->assertSee('?topic='.rawurlencode('Цены').'#pricing', false);

    LandingPage::query()->delete();
    landingOf([['type' => 'cta', 'data' => ['title' => 'Позвоните', 'primary_label' => 'Нет формы']]]);

    $this->get('/')->assertOk()->assertSee('Позвоните')->assertDontSee('Нет формы');
});

it('shows the topic over the form and saves it with the lead', function (): void {
    bootCopiedLanding($this);
    landingLeads();
    $session = Str::random(40);
    $this->withCookie(config('session.cookie'), $session);

    landingOf([
        ['type' => 'cta', 'data' => ['title' => 'Позвоните', 'primary_label' => 'Заказать', 'primary_topic' => 'Тариф <b>Бизнес</b>']],
        ['type' => 'form', 'data' => ['title' => 'Заявка', 'anchor' => 'order']],
    ]);

    $this->get('/?topic='.rawurlencode("  Тариф\n  <b>Бизнес</b>  "))->assertOk()
        ->assertSee('Тема: Тариф &lt;b&gt;Бизнес&lt;/b&gt;', false)
        ->assertSee('name="form" value="Тариф &lt;b&gt;Бизнес&lt;/b&gt;"', false)
        ->assertSee('id="form-order"', false)
        ->assertSee('name="form_anchor" value="order"', false);

    $this->get('/?topic[]=x')->assertOk()->assertDontSee('Тема:');

    $token = Crypt::encryptString((now()->timestamp - 60).'|'.$session);

    $this->withHeader('Referer', url('/?topic=Тариф'))
        ->post('/leads', ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'form' => 'Тариф Бизнес', 'form_anchor' => 'order', 'form_token' => $token])
        ->assertRedirect(url('/?topic=Тариф').'#form-order');

    expect(Lead::query()->sole()->form)->toBe('Тариф Бизнес');
});

it('leaves the form out when the leads are off and does not fail', function (): void {
    bootCopiedLanding($this);

    landingOf([['type' => 'form', 'data' => ['title' => 'Заявка', 'text' => 'Перезвоним']], ['type' => 'text', 'data' => ['title' => 'Остальное']]]);

    $this->get('/')->assertOk()->assertSee('Заявка')->assertSee('Остальное')->assertDontSee('name="form_token"', false);
});

it('shows the menu of the header from the blocks that have an anchor and a menu item', function (): void {
    bootCopiedLanding($this);

    landingOf([
        ['type' => 'header', 'data' => ['brand' => 'Север']],
        ['type' => 'text', 'data' => ['anchor' => 'about', 'menu_title' => 'О нас', 'title' => 'О нас']],
        ['type' => 'text', 'data' => ['menu_title' => 'Без якоря']],
        ['type' => 'text', 'data' => ['anchor' => 'secret', 'menu_title' => 'Скрытое', 'is_hidden' => true]],
    ]);

    $this->get('/')->assertOk()
        ->assertSee('<a href="#about">О нас</a>', false)
        ->assertSee('id="about"', false)
        ->assertDontSee('Без якоря')
        ->assertDontSee('Скрытое');
});

it('skips a block with broken data instead of failing the page', function (): void {
    bootCopiedLanding($this);
    Model::shouldBeStrict();

    landingOf([
        ['type' => 'hero', 'data' => ['title' => ['не', 'строка'], 'bullets' => 'строка', 'image' => ['x'], 'primary_label' => ['x']]],
        ['type' => 'benefits', 'data' => ['rows' => 'строка']],
        ['type' => 'pricing', 'data' => ['plans' => [['name' => ['x']], 'мусор']]],
        ['type' => 'gallery', 'data' => ['photos' => [['x'], 5]]],
        ['type' => 'reviews', 'data' => ['rows' => [['text' => 'Текст', 'rating' => 'много']]]],
        ['type' => 'faq', 'data' => ['rows' => [['question' => 7]]]],
        ['type' => 'contacts', 'data' => ['latitude' => 'x', 'longitude' => ['y']]],
        ['type' => 'footer', 'data' => ['links' => 'строка']],
        ['type' => 'no_such_type', 'data' => ['title' => 'Чужой']],
        ['type' => 'text', 'data' => ['title' => 'Живой блок']],
    ]);

    $this->get('/')->assertOk()->assertSee('Живой блок')->assertDontSee('Чужой');
});

it('keeps the anchor form for the form block even when a block before it asks for the same anchor', function (): void {
    bootCopiedLanding($this, site: false);

    $landing = landingOf([
        ['type' => 'hero', 'data' => ['title' => 'Первый экран', 'anchor' => 'form']],
        ['type' => 'form', 'data' => ['title' => 'Заявка']],
    ]);

    expect(array_column(array_column($landing->visibleBlocks(), 'data'), 'anchor'))->toBe(['', 'form'])
        ->and($landing->formAnchor())->toBe('form');

    $landing->update(['content' => [
        ['type' => 'hero', 'data' => ['title' => 'Первый экран', 'anchor' => 'form']],
        ['type' => 'form', 'data' => ['title' => 'Заявка', 'anchor' => 'order']],
    ]]);

    expect(array_column(array_column($landing->fresh()->visibleBlocks(), 'data'), 'anchor'))->toBe(['form', 'order']);
});

it('has no anchor and no menu item for the header and the footer, and survives a content that is not a list', function (): void {
    bootCopiedLanding($this, site: false);

    $landing = landingOf([
        ['type' => 'header', 'data' => ['brand' => 'Север', 'anchor' => 'top', 'menu_title' => 'Наверх']],
        ['type' => 'footer', 'data' => ['copyright' => '©', 'anchor' => 'bottom', 'menu_title' => 'Вниз']],
    ]);

    expect($landing->menu())->toBe([])
        ->and(array_column(array_column($landing->visibleBlocks(), 'data'), 'anchor'))->toBe(['', '']);

    DB::table('landing_pages')->update(['content' => '"строка"']);

    expect($landing->fresh()->visibleBlocks())->toBe([])
        ->and($landing->fresh()->menu())->toBe([]);
});

it('takes a topic from the address without invisible and direction-changing characters', function (): void {
    bootCopiedLanding($this);
    landingLeads();
    landingOf([
        ['type' => 'cta', 'data' => ['title' => 'Позвоните', 'primary_label' => 'Заказать', 'primary_topic' => "Платёж\u{202E}\u{200B} не\u{2028}прошёл"]],
        ['type' => 'form', 'data' => ['title' => 'Заявка']],
    ]);

    $this->get('/?topic='.rawurlencode("Платёж\u{202E}\u{200B} не\u{2028}прошёл"))->assertOk()->assertSee('Тема: Платёж не прошёл', false);
});

it('shows only a topic that a button of the page gives: its own topic, the name of a card, the name of a plan', function (): void {
    bootCopiedLanding($this);
    landingLeads();
    landingOf([
        ['type' => 'benefits', 'data' => ['rows' => [['title' => 'Быстро', 'btn_label' => 'Хочу'], ['title' => 'Дёшево', 'btn_label' => 'Хочу', 'btn_topic' => 'Скидка']]]],
        ['type' => 'pricing', 'data' => ['plans' => [['name' => 'Бизнес', 'btn_label' => 'Купить']]]],
        ['type' => 'cta', 'data' => ['title' => 'Длинная', 'primary_label' => 'Go', 'primary_topic' => str_repeat('я', 150)]],
        ['type' => 'form', 'data' => ['title' => 'Заявка']],
    ]);

    foreach (['Быстро', 'Скидка', 'Тариф «Бизнес»'] as $topic) {
        $this->get('/?topic='.rawurlencode($topic))->assertOk()->assertSee('Тема: '.$topic, false)->assertSee('name="form" value="'.$topic.'"', false);
    }

    $this->get('/?topic='.rawurlencode('Дёшево'))->assertOk()->assertDontSee('Тема:');
    $this->get('/?topic='.rawurlencode('Ваш аккаунт заблокирован, звоните'))->assertOk()->assertDontSee('Тема:')->assertSee('name="form" value="Заявка"', false);
    $this->get('/?topic='.rawurlencode(str_repeat('я', 150)))->assertOk()->assertSee('name="form" value="'.str_repeat('я', 100).'"', false);
});

it('does not show a topic that no button of the page carries to the form', function (): void {
    bootCopiedLanding($this);
    landingLeads();
    landingOf([
        ['type' => 'steps', 'data' => ['rows' => [['title' => 'Шаг первый', 'text' => 'Текст']]]],
        ['type' => 'benefits', 'data' => ['rows' => [
            ['title' => 'Без кнопки'],
            ['title' => 'Внешняя', 'btn_label' => 'Читать', 'btn_url' => 'https://example.com/x', 'btn_topic' => 'Чужая ссылка'],
            ['title' => '', 'btn_label' => 'Пусто', 'btn_topic' => 'Без названия'],
            ['title' => 'С якорем', 'btn_label' => 'Вниз', 'btn_url' => '#pricing'],
        ]]],
        ['type' => 'pricing', 'data' => ['plans' => [['name' => '', 'btn_label' => 'Купить']]]],
        ['type' => 'cta', 'data' => ['title' => 'Призыв', 'primary_label' => '', 'primary_topic' => 'Кнопка без подписи']],
        ['type' => 'form', 'data' => ['title' => 'Заявка']],
    ]);

    foreach (['Шаг первый', 'Без кнопки', 'Чужая ссылка', 'Внешняя', 'Без названия', 'Кнопка без подписи', 'Тариф «»'] as $topic) {
        $this->get('/?topic='.rawurlencode($topic))->assertOk()->assertDontSee('Тема:');
    }

    $this->get('/?topic='.rawurlencode('С якорем'))->assertOk()->assertSee('Тема: С якорем', false);
});

it('shows no topic when a button without a link has no form to lead to', function (): void {
    bootCopiedLanding($this);
    landingLeads();
    landingOf([['type' => 'cta', 'data' => ['title' => 'Призыв', 'primary_label' => 'Заказать', 'primary_topic' => 'Без формы']]]);

    $this->get('/?topic='.rawurlencode('Без формы'))->assertOk()->assertDontSee('Тема:');
});

it('shows the topic of every button link of the example page over its form', function (): void {
    bootCopiedLanding($this);
    landingLeads();
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\LandingPageSeeder', '--no-interaction' => true])->assertSuccessful();

    preg_match_all('~href="[^"]*\?topic=([^"#]+)#~', (string) $this->get('/')->getContent(), $links);

    expect($links[1])->not->toBeEmpty();

    foreach (array_unique($links[1]) as $encoded) {
        $this->get('/?topic='.$encoded)->assertOk()->assertSee('Тема: '.rawurldecode($encoded), false);
    }
});

it('sends a slash at the end to the real address of the landing and keeps the query', function (): void {
    bootCopiedLanding($this);
    landingOf([['type' => 'text', 'data' => ['title' => 'Акционный']]], url: '/promo');
    landingOf([['type' => 'text', 'data' => ['title' => 'Главный']]]);

    foreach (['/promo/' => '/promo', '/promo/?utm=x' => '/promo?utm=x', '/promo//' => '/promo'] as $requested => $real) {
        $response = $this->app->handle(Request::create($requested));

        expect($response->getStatusCode())->toBe(301)
            ->and($response->headers->get('Location'))->toBe(url($real));
    }

    $this->get('/promo?utm=x')->assertOk();
    $this->get('/?topic=x')->assertOk()->assertSee('Главный');
    $this->get('/pr%6Fmo')->assertOk();
});

it('accepts only whole ratings in the reviews block', function (): void {
    bootCopiedLanding($this, site: false);
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->post(route('fastleo.resources.store', 'landing'), ['title' => 'Студия', 'url' => '/', 'is_published' => '1', 'content' => [
        'a' => ['type' => 'reviews', 'reviews' => ['rows' => [['author' => 'Анна', 'text' => 'Отлично', 'rating' => '4.5']]]],
    ]])->assertSessionHasErrors();

    expect(LandingPage::query()->count())->toBe(0);
});

it('gives every form its own anchor and shows the topic of the link only over the first form', function (): void {
    bootCopiedLanding($this);
    landingLeads();

    landingOf([
        ['type' => 'hero', 'data' => ['title' => 'Первый экран', 'primary_label' => 'Заказать', 'primary_topic' => 'Новый сайт', 'second_label' => 'Тариф', 'second_topic' => 'Тариф Бизнес']],
        ['type' => 'form', 'data' => ['title' => 'Первая форма']],
        ['type' => 'form', 'data' => ['title' => 'Вторая форма']],
        ['type' => 'form', 'data' => ['title' => 'Третья форма', 'anchor' => 'form-2']],
    ]);

    $html = (string) $this->get('/?topic='.rawurlencode('Тариф Бизнес'))->assertOk()->getContent();

    expect(substr_count($html, 'Тема: Тариф Бизнес'))->toBe(1)
        ->and(substr_count($html, 'name="form" value="Тариф Бизнес"'))->toBe(1)
        ->and($html)->toContain('id="form-form"')->toContain('name="form" value="Вторая форма"')->toContain('id="form-form-3"')->toContain('id="form-form-2"')
        ->toContain('id="form-2"')
        ->toContain('/?topic='.rawurlencode('Новый сайт').'#form"');
});

it('marks links to other sites with rel in the footer, the consent and the buttons, and leaves own links alone', function (): void {
    bootCopiedLanding($this);
    landingLeads();

    landingOf([
        ['type' => 'cta', 'data' => ['title' => 'Позвоните', 'primary_label' => 'Внешняя', 'primary_url' => 'https://example.com/x']],
        ['type' => 'form', 'data' => ['title' => 'Заявка', 'consent' => 'Согласие', 'consent_url' => 'HTTPS://example.com/policy']],
        ['type' => 'footer', 'data' => ['links' => [['title' => 'Чужая', 'url' => 'https://example.com/a'], ['title' => 'Своя', 'url' => '/about']], 'policy_title' => 'Политика', 'policy_url' => 'https://example.com/privacy']],
    ]);

    $html = (string) $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, 'rel="noopener noreferrer nofollow"'))->toBe(4)
        ->and($html)->toContain('<a href="/about">Своя</a>');
});

it('refuses a consent link that is not an address of the site or a web address', function (string $url): void {
    bootCopiedLanding($this);
    landingLeads();
    landingOf([['type' => 'form', 'data' => ['title' => 'Заявка', 'consent' => 'Согласие на обработку', 'consent_url' => $url]]]);

    $this->get('/')->assertOk()->assertSee('Согласие на обработку')->assertDontSee('href="'.$url.'"', false);
})->with(['javascript:alert(1)', '//evil.example', 'data:text/html,x', 'https://', 'http:///x', 'https://?a=1']);

it('builds the link to the map from the coordinates, the number of the phone without a plus, and the alt of a logo without a brand', function (): void {
    bootCopiedLanding($this);

    landingOf([
        ['type' => 'header', 'data' => ['logo' => 'uploads/landing/logo.png', 'phone' => '8 (343) 000-00-00']],
        ['type' => 'contacts', 'data' => ['phone' => '8 343 000-00-00', 'address' => 'Адрес', 'latitude' => '56.84', 'longitude' => 60.6]],
    ]);

    $this->get('/')->assertOk()
        ->assertSee('alt="Логотип"', false)
        ->assertSee('href="tel:83430000000"', false)
        ->assertSee('yandex.ru/maps/?pt=60.6,56.84&amp;z=16&amp;l=map', false);
});

it('leads a card button to the form with the name of the card, and keeps a link of the second button of the first screen', function (): void {
    bootCopiedLanding($this);
    landingLeads();

    landingOf([
        ['type' => 'hero', 'data' => ['title' => 'Первый экран', 'second_label' => 'О нас', 'second_url' => '/about', 'image' => 'uploads/landing/hero.png', 'image_position' => 'не из списка']],
        ['type' => 'benefits', 'data' => ['rows' => [['title' => 'Быстро', 'btn_label' => 'Хочу']]]],
        ['type' => 'form', 'data' => ['title' => 'Заявка']],
    ]);

    $this->get('/')->assertOk()
        ->assertSee('href="/about"', false)
        ->assertSee('landing-hero--right', false)
        ->assertSee('?topic='.rawurlencode('Быстро').'#form"', false);
});

it('does not draw a player for a video that is not from a known site', function (string $url): void {
    bootCopiedLanding($this);
    landingOf([['type' => 'video', 'data' => ['title' => 'Видео', 'video' => $url]]]);

    $this->get('/')->assertOk()->assertSee('Видео')->assertDontSee('<iframe', false);
})->with(['https://evil.example/watch?v=1', 'javascript:alert(1)']);
