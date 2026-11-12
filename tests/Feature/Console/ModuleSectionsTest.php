<?php

declare(strict_types=1);

use App\FastleoSections\ContactsSettings;
use App\FastleoSections\Models\Branch;
use App\FastleoSections\Models\Certificate;
use App\FastleoSections\Models\FaqItem;
use App\FastleoSections\Models\MenuItem;
use App\FastleoSections\Models\Partner;
use App\FastleoSections\Models\PortfolioItem;
use App\FastleoSections\Models\PriceItem;
use App\FastleoSections\Models\Review;
use App\FastleoSections\Models\Service;
use App\FastleoSections\Models\TeamMember;
use Fastleo\Fastleo;
use Fastleo\Installer\Modules;
use Fastleo\Support\SettingsStore;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Модули «Меню сайта», «Отзывы», «Портфолио», «Вопросы и ответы», «Услуги», «Прайс-лист», «Команда», «Филиалы», «Лицензии и сертификаты», «Партнёры»: код копируется в сайт и поднимается по-настоящему.
 */
const SECTION_MODULES = [
    'landing' => ['table' => 'landing_pages', 'slug' => 'landing', 'resource' => 'LandingPageResource', 'model' => 'LandingPage', 'store' => ['title' => 'Студия', 'url' => '/', 'is_published' => '1']],
    'menu' => ['table' => 'menu_items', 'slug' => 'menu-items', 'resource' => 'MenuItemResource', 'model' => 'MenuItem', 'store' => ['title' => 'Контакты', 'url' => '/contacts', 'location' => 'header', 'is_published' => '1']],
    'reviews' => ['table' => 'reviews', 'slug' => 'reviews', 'resource' => 'ReviewResource', 'model' => 'Review', 'store' => ['author' => 'Анна', 'rating' => '5', 'body' => 'Спасибо!', 'is_published' => '1']],
    'portfolio' => ['table' => 'portfolio_items', 'slug' => 'portfolio', 'resource' => 'PortfolioResource', 'model' => 'PortfolioItem', 'store' => ['title' => 'Работа', 'slug' => 'rabota', 'is_published' => '1']],
    'faq' => ['table' => 'faq_items', 'slug' => 'faq', 'resource' => 'FaqResource', 'model' => 'FaqItem', 'store' => ['question' => 'Как заказать?', 'answer' => '<p>Позвоните.</p>', 'is_published' => '1']],
    'services' => ['table' => 'services', 'slug' => 'services', 'resource' => 'ServiceResource', 'model' => 'Service', 'store' => ['title' => 'Услуга', 'slug' => 'usluga', 'is_published' => '1']],
    'prices' => ['table' => 'price_items', 'slug' => 'prices', 'resource' => 'PriceItemResource', 'model' => 'PriceItem', 'store' => ['title' => 'Стрижка', 'price' => '1 500 ₽', 'is_published' => '1']],
    'team' => ['table' => 'team_members', 'slug' => 'team', 'resource' => 'TeamMemberResource', 'model' => 'TeamMember', 'store' => ['name' => 'Анна', 'slug' => 'anna', 'is_published' => '1']],
    'branches' => ['table' => 'branches', 'slug' => 'branches', 'resource' => 'BranchResource', 'model' => 'Branch', 'store' => ['name' => 'Офис', 'address' => 'ул. Тверская, 1', 'is_published' => '1']],
    'certificates' => ['table' => 'certificates', 'slug' => 'certificates', 'resource' => 'CertificateResource', 'model' => 'Certificate', 'store' => ['title' => 'Лицензия', 'is_published' => '1']],
    'partners' => ['table' => 'partners', 'slug' => 'partners', 'resource' => 'PartnerResource', 'model' => 'Partner', 'store' => ['name' => 'Ромашка', 'url' => 'https://example.com', 'is_published' => '1']],
];

beforeEach(function (): void {
    // Пишем в временную папку: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-sections-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/routes');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
    File::put($this->sandbox.'/composer.json', '{"autoload": {"psr-4": {"App\\\\FastleoSections\\\\": "app/"}}}');
    sandboxWithPest($this->sandbox);
    $this->app->setBasePath($this->sandbox);

    foreach (SECTION_MODULES as $module) {
        Schema::dropIfExists($module['table']);
    }

    config(['fastleo.resources.path' => $this->sandbox.'/app/Fastleo', 'fastleo.resources.namespace' => 'App\\FastleoSections']);
});

afterEach(function (): void {
    Model::shouldBeStrict(false);
    Factory::flushState();
    File::deleteDirectory($this->sandbox);
});

/**
 * Скопировать модуль в сайт и поднять его: миграция, классы (один раз на процесс), фабрики.
 */
function bootCopiedSection(object $test, string $name): void
{
    $module = SECTION_MODULES[$name];

    $test->artisan('fastleo:module', ['modules' => [$name], '--no-interaction' => true])->assertSuccessful();
    $test->artisan('migrate', ['--path' => $test->sandbox.'/database/migrations', '--realpath' => true])->assertSuccessful();

    // Класс-спутник раздела (типы блоков лендинга) должен быть загружен раньше самого раздела.
    foreach (['LandingBlocks'] as $companion) {
        if (File::exists($test->sandbox.'/app/Fastleo/'.$companion.'.php') && ! class_exists('App\\FastleoSections\\'.$companion, false)) {
            require $test->sandbox.'/app/Fastleo/'.$companion.'.php';
        }
    }

    foreach (['app/Models/'.$module['model'] => 'App\\FastleoSections\\Models\\'.$module['model'], 'app/Fastleo/'.$module['resource'] => 'App\\FastleoSections\\'.$module['resource'], 'database/factories/'.$module['model'].'Factory' => 'Database\\Factories\\'.$module['model'].'Factory'] as $file => $class) {
        if (! class_exists($class, false)) {
            require $test->sandbox.'/'.$file.'.php';
        }
    }

    Fastleo::resources()->flush();
}

it('is in the list of modules with its own tables', function (): void {
    expect(array_keys(Modules::all()))->toBe(['contacts', 'pages', 'landing', 'news', 'menu', 'reviews', 'portfolio', 'faq', 'services', 'prices', 'team', 'branches', 'certificates', 'partners', 'shop'])
        ->and(Modules::find('menu')->tables)->toBe(['menu_items'])
        ->and(Modules::find('faq')->tables)->toBe(['faq_items']);
});

it('copies the module with its migration, factory, seeder, test and public part and touches nothing else', function (string $name): void {
    $module = SECTION_MODULES[$name];

    $this->artisan('fastleo:module', ['modules' => [$name], '--no-interaction' => true])
        ->expectsOutputToContain('app/Models/'.$module['model'].'.php')
        ->assertSuccessful();

    expect(File::exists($this->sandbox.'/app/Fastleo/'.$module['resource'].'.php'))->toBeTrue()
        ->and(glob($this->sandbox.'/database/migrations/*_create_'.$module['table'].'_table.php'))->toHaveCount(1)
        ->and(File::exists($this->sandbox.'/database/factories/'.$module['model'].'Factory.php'))->toBeTrue()
        ->and(File::exists($this->sandbox.'/database/seeders/'.$module['model'].'Seeder.php'))->toBeTrue()
        ->and($this->sandbox.'/resources/views/layouts/site.blade.php')->toBeFile()
        ->and($this->sandbox.'/app/Fastleo/Dashboard.php')->not->toBeFile();
})->with(array_keys(SECTION_MODULES));

it('installs all the modules together: no clash of files, tables or route names, migrations that run, the pages fallback last', function (): void {
    $modules = Modules::all();

    foreach ($modules as $module) {
        foreach (array_reverse($module->tables) as $table) {
            Schema::dropIfExists($table);
        }
    }

    $this->artisan('fastleo:module', ['modules' => array_keys($modules), '--no-interaction' => true])->assertSuccessful();
    $this->artisan('migrate', ['--path' => $this->sandbox.'/database/migrations', '--realpath' => true])->assertSuccessful();

    foreach ($modules as $module) {
        foreach ($module->tables as $table) {
            expect(Schema::hasTable($table))->toBeTrue($table);
        }
    }

    $routes = File::get($this->sandbox.'/routes/web.php');
    preg_match_all("/->name\('(site\.[a-z.]+)'\)/", $routes, $names);

    expect($names[1])->not->toBeEmpty()
        ->and(array_unique($names[1]))->toHaveCount(count($names[1]))
        ->and(strrpos($routes, 'Route::fallback'))->toBeGreaterThan((int) strrpos($routes, "->name('site."));
});

it('does not install a module over an existing table of the site', function (string $name): void {
    Schema::create(SECTION_MODULES[$name]['table'], function ($table): void {
        $table->id();
    });

    $this->artisan('fastleo:module', ['modules' => [$name], '--no-interaction' => true])->assertFailed();

    expect($this->sandbox.'/app/Fastleo')->not->toBeDirectory();
})->with(array_keys(SECTION_MODULES));

it('gives the administrator a working section that passes fastleo:doctor', function (string $name): void {
    $module = SECTION_MODULES[$name];
    bootCopiedSection($this, $name);
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->get(route('fastleo.resources.index', $module['slug']))->assertOk();
    $this->get(route('fastleo.resources.create', $module['slug']))->assertOk();
    $this->post(route('fastleo.resources.store', $module['slug']), $module['store'])->assertSessionHasNoErrors()->assertRedirect();

    expect(DB::table($module['table'])->count())->toBe(1);

    Artisan::call('fastleo:doctor', ['--json' => true]);
    $problems = collect(json_decode(Artisan::output(), true)['problems'])->where('scope', $module['slug'])->where('level', 'error');

    expect($problems->all())->toBe([]);
})->with(array_keys(SECTION_MODULES));

it('shows published reviews, works and questions in the order of the panel', function (string $name, string $column, string $first, string $second): void {
    bootCopiedSection($this, $name);
    $model = 'App\\FastleoSections\\Models\\'.SECTION_MODULES[$name]['model'];

    $model::factory()->create([$column => $second, 'sort' => 2]);
    $model::factory()->create([$column => $first, 'sort' => 1]);
    $model::factory()->create([$column => 'Скрытая запись', 'is_published' => false, 'sort' => 0]);

    expect($model::query()->published()->pluck($column)->all())->toBe([$first, $second]);
})->with([
    'reviews' => ['reviews', 'author', 'Первый', 'Второй'],
    'portfolio' => ['portfolio', 'title', 'Первая', 'Вторая'],
    'faq' => ['faq', 'question', 'Первый?', 'Второй?'],
    'services' => ['services', 'title', 'Первая', 'Вторая'],
    'prices' => ['prices', 'title', 'Первая', 'Вторая'],
    'team' => ['team', 'name', 'Первый', 'Второй'],
    'branches' => ['branches', 'name', 'Первый', 'Второй'],
    'certificates' => ['certificates', 'title', 'Первый', 'Второй'],
    'partners' => ['partners', 'name', 'Первый', 'Второй'],
]);

it('builds the menu tree from published items of one place only', function (): void {
    bootCopiedSection($this, 'menu');

    $about = MenuItem::factory()->create(['title' => 'О нас', 'url' => '/about', 'sort' => 2]);
    MenuItem::factory()->create(['title' => 'Главная', 'url' => '/', 'sort' => 1]);
    MenuItem::factory()->create(['title' => 'Команда', 'url' => '/team', 'parent_id' => $about->id]);
    MenuItem::factory()->create(['title' => 'Скрытый', 'url' => '/hidden', 'is_published' => false, 'sort' => 3]);
    MenuItem::factory()->create(['title' => 'Политика', 'url' => '/privacy', 'location' => MenuItem::FOOTER]);

    $tree = MenuItem::tree('header');

    expect($tree->pluck('title')->all())->toBe(['Главная', 'О нас'])
        ->and($tree->first()->children->all())->toBe([])
        ->and($tree->last()->children->pluck('title')->all())->toBe(['Команда'])
        ->and(MenuItem::tree(MenuItem::FOOTER)->pluck('title')->all())->toBe(['Политика']);
});

it('leaves out the items of a hidden parent of the menu', function (): void {
    bootCopiedSection($this, 'menu');
    $hidden = MenuItem::factory()->create(['title' => 'Скрытый', 'is_published' => false]);
    MenuItem::factory()->create(['title' => 'Вложенный', 'parent_id' => $hidden->id]);

    expect(MenuItem::tree()->all())->toBe([]);
});

it('takes the parent of a menu item only from the same place, where the item is shown', function (): void {
    bootCopiedSection($this, 'menu');
    $this->actingAs(admin(), Fastleo::GUARD);
    $header = MenuItem::factory()->create(['title' => 'Шапка', 'location' => MenuItem::HEADER]);
    $footer = MenuItem::factory()->create(['title' => 'Подвал', 'location' => MenuItem::FOOTER]);
    $item = ['title' => 'Вложенный', 'url' => '/inner', 'location' => MenuItem::FOOTER];

    $this->post(route('fastleo.resources.store', 'menu-items'), [...$item, 'parent_id' => $header->id])->assertSessionHasErrors('parent_id');
    $this->post(route('fastleo.resources.store', 'menu-items'), [...$item, 'parent_id' => $footer->id])->assertSessionHasNoErrors();

    expect(MenuItem::tree(MenuItem::FOOTER)->sole()->children->pluck('title')->all())->toBe(['Вложенный']);
});

it('refuses a menu address that is not a path of the site or a web address', function (): void {
    bootCopiedSection($this, 'menu');
    $this->actingAs(admin(), Fastleo::GUARD);

    foreach (['javascript:alert(1)', '//evil.example', 'contacts'] as $url) {
        $this->post(route('fastleo.resources.store', 'menu-items'), ['title' => 'Пункт', 'url' => $url, 'location' => 'header'])->assertSessionHasErrors('url');
    }

    $this->post(route('fastleo.resources.store', 'menu-items'), ['title' => 'Пункт', 'url' => 'https://example.com/a', 'location' => 'footer'])->assertSessionHasNoErrors();

    expect(MenuItem::query()->count())->toBe(1);
});

it('accepts only a whole rating from 1 to 5', function (): void {
    bootCopiedSection($this, 'reviews');
    $this->actingAs(admin(), Fastleo::GUARD);

    foreach (['4.5', '0', '6'] as $rating) {
        $this->post(route('fastleo.resources.store', 'reviews'), ['author' => 'Анна', 'rating' => $rating, 'body' => 'Спасибо!'])->assertSessionHasErrors('rating');
    }

    expect(DB::table('reviews')->count())->toBe(0);
});

it('puts the sections of the older modules in the top of the menu, without a group (the shop has its own, the company modules share one)', function (): void {
    $stubs = glob(dirname(__DIR__, 3).'/stubs/modules/*/fastleo/*Resource.php.stub');
    $company = ['services', 'prices', 'team', 'branches', 'certificates', 'partners'];

    expect($stubs)->toHaveCount(17);

    foreach ($stubs as $stub) {
        $module = basename(dirname($stub, 2));

        if (in_array($module, $company, true)) {
            expect(File::get($stub))->toContain("\$group = 'Компания'");
        } elseif ($module !== 'shop') {
            expect(File::get($stub))->not->toContain('$group');
        }
    }
});

it('tells the next steps after copying: migrate and, if wanted, the seeders of the added modules', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['menu', 'faq'], '--no-interaction' => true])
        ->expectsOutputToContain('Дальше:')
        ->expectsOutputToContain('php artisan migrate')
        ->expectsOutputToContain('php artisan db:seed --class=\'Database\\Seeders\\MenuItemSeeder\'; php artisan db:seed --class=\'Database\\Seeders\\FaqItemSeeder\'')
        ->assertSuccessful();
});

it('does not tell about migrations or seeders after a module without tables', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['contacts'], '--no-interaction' => true])
        ->doesntExpectOutputToContain('Дальше:')
        ->assertSuccessful();
});

it('shows the header and the footer menu with the children of the items and without hidden items', function (): void {
    bootCopiedSection($this, 'menu');
    bootSiteRoutes($this, [], []);
    Model::shouldBeStrict();
    $about = MenuItem::factory()->create(['title' => 'О нас', 'url' => '/about', 'sort' => 1]);
    MenuItem::factory()->create(['title' => 'Команда', 'url' => '/team', 'parent_id' => $about->id]);
    MenuItem::factory()->create(['title' => 'Скрытый', 'url' => '/hidden', 'is_published' => false]);
    MenuItem::factory()->create(['title' => 'Внешний', 'url' => 'https://example.com/x', 'new_tab' => true, 'sort' => 2]);
    MenuItem::factory()->create(['title' => 'Политика', 'url' => '/privacy', 'location' => MenuItem::FOOTER]);

    $html = view('layouts.site')->render();

    expect($html)->toContain('<a href="/about">О нас</a>')
        ->toContain('<a href="/team">Команда</a>')
        ->toContain('<a href="https://example.com/x" target="_blank" rel="noopener">Внешний</a>')
        ->toContain('<a href="/privacy">Политика</a>')
        ->not->toContain('Скрытый');
});

it('prints the title of a menu item without a link when its address is not safe and escapes the title', function (string $url): void {
    bootCopiedSection($this, 'menu');
    bootSiteRoutes($this, [], []);
    MenuItem::factory()->create(['title' => 'Пункт <script>alert(1)</script>', 'url' => $url]);

    $html = view('layouts.site')->render();

    expect($html)->toContain('Пункт &lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('href="'.$url.'"');
})->with([
    'script' => ['javascript:alert(1)'],
    'mixed case script' => ['JaVaScRiPt:alert(1)'],
    'protocol relative' => ['//evil.example'],
    'backslash' => ['/\\evil.example'],
    'scheme without slashes' => ['http:evil.example'],
]);

it('shows no menu in the layout until there is an item', function (): void {
    bootCopiedSection($this, 'menu');
    bootSiteRoutes($this, [], []);

    expect(view('layouts.site')->render())->not->toContain('<ul>');
});

it('shows the contacts page with the values of the settings and hides the empty ones', function (): void {
    forgetDemoRoutes();
    $this->artisan('fastleo:module', ['modules' => ['contacts'], '--no-interaction' => true])->assertSuccessful();

    if (! class_exists('App\\FastleoSections\\ContactsSettings', false)) {
        require $this->sandbox.'/app/Fastleo/ContactsSettings.php';
    }

    Fastleo::resources()->flush();
    bootSiteRoutes($this, ['App\\FastleoSections\\Http\\Controllers\\ContactsController' => 'app/Http/Controllers/ContactsController.php'], ['routes/web.php']);
    resolve(SettingsStore::class)->save(new ContactsSettings(), ['phone' => '+7 900 000-00-00', 'email' => 'info@example.com', 'address' => '', 'working_hours' => "Пн-Пт 9-18\n<b>Сб</b> выходной"]);

    $this->get('/contacts')->assertOk()
        ->assertSee('tel:+79000000000', false)
        ->assertSee('mailto:info@example.com', false)
        ->assertSee('Пн-Пт 9-18<br />', false)
        ->assertSee('&lt;b&gt;Сб&lt;/b&gt; выходной', false)
        ->assertDontSee('Адрес:');
});

it('installs the contacts module without the page when the site has a page of its own at /contacts', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['contacts']])
        ->expectsOutputToContain('Публичная часть модуля contacts не добавлена')
        ->expectsOutputToContain('адрес /contacts уже занят')
        ->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo/ContactsSettings.php')->toBeFile()
        ->and(siteRoutesOf($this))->not->toContain('ContactsController::class');
});

/**
 * Поднять публичную часть модуля с контроллером и маршрутами (отзывы, портфолио, вопросы и ответы).
 */
function bootCopiedSectionSite(object $test, string $name): void
{
    $controller = ['reviews' => 'ReviewController', 'portfolio' => 'PortfolioController', 'faq' => 'FaqController', 'services' => 'ServiceController', 'prices' => 'PriceController', 'team' => 'TeamController', 'branches' => 'BranchController', 'certificates' => 'CertificateController', 'partners' => 'PartnerController'][$name];

    bootCopiedSection($test, $name);
    bootSiteRoutes($test, ['App\\FastleoSections\\Http\\Controllers\\'.$controller => 'app/Http/Controllers/'.$controller.'.php'], ['routes/web.php']);
}

it('shows the published reviews in the order of the panel and hides the rest', function (): void {
    bootCopiedSectionSite($this, 'reviews');
    Model::shouldBeStrict();
    Review::factory()->create(['author' => 'Вторая', 'body' => 'Текст второй', 'sort' => 2]);
    Review::factory()->create(['author' => 'Первая', 'body' => 'Текст первой', 'company' => 'ООО «Ромашка»', 'rating' => 4, 'sort' => 1]);
    Review::factory()->create(['author' => 'Скрытая', 'is_published' => false, 'sort' => 0]);

    $this->get('/reviews')->assertOk()
        ->assertSeeInOrder(['Первая', 'ООО «Ромашка»', 'Оценка: 4 из 5', 'Текст первой', 'Вторая'])
        ->assertDontSee('Скрытая');
});

it('escapes the fields of a review and keeps its line breaks', function (): void {
    bootCopiedSectionSite($this, 'reviews');
    Review::factory()->create(['author' => '<script>alert(1)</script>', 'company' => '<img src=x onerror=alert(2)>', 'body' => "Первая строка\n<b>вторая</b>"]);

    $this->get('/reviews')->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('<img src=x', false)
        ->assertSee('Первая строка<br />', false)
        ->assertSee('&lt;b&gt;вторая&lt;/b&gt;', false);
});

it('pages the reviews twenty at a time', function (): void {
    bootCopiedSectionSite($this, 'reviews');
    Review::factory()->count(21)->create();

    $this->get('/reviews')->assertOk()->assertSee('rel="next"', false)->assertDontSee('rel="prev"', false);
    $this->get('/reviews?page=2')->assertOk()->assertSee('rel="prev"', false);
});

it('lists the published works and opens one by its address with its details and photos', function (): void {
    bootCopiedSectionSite($this, 'portfolio');
    Model::shouldBeStrict();
    PortfolioItem::factory()->create(['title' => 'Сайт кофейни', 'slug' => 'coffee', 'category' => 'Сайты', 'client' => 'Кофейня', 'excerpt' => 'Кратко о работе', 'body' => '<p>Подробности</p>', 'photos' => ['uploads/portfolio/one.jpg', 'uploads/portfolio/two.jpg'], 'completed_at' => '2026-03-01']);
    PortfolioItem::factory()->create(['title' => 'Скрытая работа', 'slug' => 'hidden', 'is_published' => false]);

    $this->get('/portfolio')->assertOk()->assertSee('Сайт кофейни')->assertSee('Сайты')->assertSee('Кратко о работе')->assertDontSee('Скрытая работа');
    $this->get('/portfolio/coffee')->assertOk()
        ->assertSee('Заказчик: Кофейня')
        ->assertSee('01.03.2026')
        ->assertSee('<p>Подробности</p>', false)
        ->assertSee('uploads/portfolio/one.jpg', false)
        ->assertSee('uploads/portfolio/two.jpg', false);

    foreach (['hidden', 'no-such-work', 'Coffee', 'a%00b'] as $slug) {
        $this->get('/portfolio/'.$slug)->assertNotFound();
    }
});

it('escapes the fields of a work and pages the list twelve at a time', function (): void {
    bootCopiedSectionSite($this, 'portfolio');
    PortfolioItem::factory()->create(['title' => '<script>alert(1)</script>', 'slug' => 'xss', 'category' => '<i>x</i>', 'client' => '<u>y</u>']);

    foreach (['/portfolio', '/portfolio/xss'] as $url) {
        $this->get($url)->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<i>x</i>', false)->assertDontSee('<u>y</u>', false);
    }

    PortfolioItem::factory()->count(12)->create();

    $this->get('/portfolio')->assertOk()->assertSee('rel="next"', false);
    $this->get('/portfolio?page=2')->assertOk()->assertSee('rel="prev"', false);
});

it('shows the published questions without a group first and then the groups in the order of the panel', function (): void {
    bootCopiedSectionSite($this, 'faq');
    Model::shouldBeStrict();
    FaqItem::factory()->create(['question' => 'Как оплатить?', 'answer' => '<p>Картой.</p>', 'category' => 'Оплата', 'sort' => 1]);
    FaqItem::factory()->create(['question' => 'Где вы находитесь?', 'answer' => '<p>В центре.</p>', 'category' => null, 'sort' => 2]);
    FaqItem::factory()->create(['question' => 'Есть ли доставка?', 'answer' => '<p>Да.</p>', 'category' => 'Доставка', 'sort' => 3]);
    FaqItem::factory()->create(['question' => 'Скрытый вопрос?', 'is_published' => false]);

    $this->get('/faq')->assertOk()
        ->assertSeeInOrder(['Где вы находитесь?', '<p>В центре.</p>', 'Оплата', 'Как оплатить?', '<p>Картой.</p>', 'Доставка', 'Есть ли доставка?'], false)
        ->assertDontSee('Скрытый вопрос?');
});

it('titles a group of questions called 0 and does not mistake it for the questions without a group', function (): void {
    bootCopiedSectionSite($this, 'faq');
    FaqItem::factory()->create(['question' => 'Вопрос из нулевой группы?', 'category' => '0', 'sort' => 1]);
    FaqItem::factory()->create(['question' => 'Вопрос без группы?', 'category' => null, 'sort' => 2]);

    $this->get('/faq')->assertOk()->assertSeeInOrder(['Вопрос без группы?', '<h2>0</h2>', 'Вопрос из нулевой группы?'], false);
});

it('escapes the question and the group of the questions and shows a text when there are none', function (): void {
    bootCopiedSectionSite($this, 'faq');

    $this->get('/faq')->assertOk()->assertSee('Вопросов пока нет.');

    FaqItem::factory()->create(['question' => '<script>alert(1)</script>', 'category' => '<img src=x onerror=alert(2)>']);

    $this->get('/faq')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false);
});

it('lists the published services with their price and opens one by its address', function (): void {
    bootCopiedSectionSite($this, 'services');
    Model::shouldBeStrict();
    Service::factory()->create(['title' => 'Настройка сервера', 'slug' => 'server', 'category' => 'Обслуживание', 'price' => 'от 1 500 ₽', 'excerpt' => 'Кратко об услуге', 'body' => '<p>Подробности</p>']);
    Service::factory()->create(['title' => 'Скрытая услуга', 'slug' => 'hidden', 'is_published' => false]);

    $this->get('/services')->assertOk()->assertSee('Настройка сервера')->assertSee('Обслуживание')->assertSee('от 1 500 ₽')->assertSee('Кратко об услуге')->assertDontSee('Скрытая услуга');
    $this->get('/services/server')->assertOk()->assertSee('от 1 500 ₽')->assertSee('<p>Подробности</p>', false);

    foreach (['hidden', 'no-such-service', 'Server', 'a%00b'] as $slug) {
        $this->get('/services/'.$slug)->assertNotFound();
    }
});

it('escapes the fields of a service and pages the list twelve at a time', function (): void {
    bootCopiedSectionSite($this, 'services');
    Service::factory()->create(['title' => '<script>alert(1)</script>', 'slug' => 'xss', 'category' => '<i>x</i>', 'price' => '<u>y</u>']);

    foreach (['/services', '/services/xss'] as $url) {
        $this->get($url)->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<i>x</i>', false)->assertDontSee('<u>y</u>', false);
    }

    Service::factory()->count(12)->create();

    $this->get('/services')->assertOk()->assertSee('rel="next"', false);
    $this->get('/services?page=2')->assertOk()->assertSee('rel="prev"', false);
});

it('lists the published team members and opens one by its address with the job title and experience', function (): void {
    bootCopiedSectionSite($this, 'team');
    Model::shouldBeStrict();
    TeamMember::factory()->create(['name' => 'Анна Иванова', 'slug' => 'anna', 'job_title' => 'Старший мастер', 'experience' => 'Стаж 12 лет', 'excerpt' => 'Кратко о мастере', 'body' => '<p>Подробности</p>']);
    TeamMember::factory()->create(['name' => 'Скрытый сотрудник', 'slug' => 'hidden', 'is_published' => false]);

    $this->get('/team')->assertOk()->assertSee('Анна Иванова')->assertSee('Старший мастер')->assertSee('Стаж 12 лет')->assertSee('Кратко о мастере')->assertDontSee('Скрытый сотрудник');
    $this->get('/team/anna')->assertOk()->assertSee('Старший мастер')->assertSee('<p>Подробности</p>', false);

    foreach (['hidden', 'no-such-member', 'Anna', 'a%00b'] as $slug) {
        $this->get('/team/'.$slug)->assertNotFound();
    }
});

it('escapes the fields of a team member and pages the list twelve at a time', function (): void {
    bootCopiedSectionSite($this, 'team');
    TeamMember::factory()->create(['name' => '<script>alert(1)</script>', 'slug' => 'xss', 'job_title' => '<i>x</i>', 'experience' => '<u>y</u>']);

    foreach (['/team', '/team/xss'] as $url) {
        $this->get($url)->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<i>x</i>', false)->assertDontSee('<u>y</u>', false);
    }

    TeamMember::factory()->count(12)->create();

    $this->get('/team')->assertOk()->assertSee('rel="next"', false);
    $this->get('/team?page=2')->assertOk()->assertSee('rel="prev"', false);
});

it('shows the prices by groups: the items without a group first, then the groups in the order of the panel', function (): void {
    bootCopiedSectionSite($this, 'prices');
    Model::shouldBeStrict();
    PriceItem::factory()->create(['title' => 'Окрашивание', 'category' => 'Окрашивание', 'price' => 'от 3 000 ₽', 'unit' => 'за визит', 'sort' => 1]);
    PriceItem::factory()->create(['title' => 'Консультация', 'category' => null, 'price' => 'бесплатно', 'note' => 'по записи', 'sort' => 2]);
    PriceItem::factory()->create(['title' => 'Стрижка', 'category' => 'Стрижки', 'price' => '1 500 ₽', 'sort' => 3]);
    PriceItem::factory()->create(['title' => 'Скрытая позиция', 'is_published' => false]);

    $this->get('/prices')->assertOk()
        ->assertSeeInOrder(['Консультация', 'по записи', 'бесплатно', 'Окрашивание', 'от 3 000 ₽ за визит', 'Стрижки', 'Стрижка', '1 500 ₽'])
        ->assertDontSee('Скрытая позиция');
});

it('titles a group of prices called 0, escapes the fields and shows a text when there are no prices', function (): void {
    bootCopiedSectionSite($this, 'prices');

    $this->get('/prices')->assertOk()->assertSee('Цен пока нет.');

    PriceItem::factory()->create(['title' => '<script>alert(1)</script>', 'category' => '0', 'price' => '<u>1</u>', 'note' => '<i>x</i>', 'sort' => 2]);
    PriceItem::factory()->create(['title' => 'Без группы', 'category' => null, 'sort' => 1]);

    $this->get('/prices')->assertOk()
        ->assertSeeInOrder(['Без группы', '<h2>0</h2>'], false)
        ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<u>1</u>', false)->assertDontSee('<i>x</i>', false);
});

it('shows the published documents and links the file before the scan, the scan when there is no file, nothing when there is neither', function (): void {
    bootCopiedSectionSite($this, 'certificates');
    Model::shouldBeStrict();
    Certificate::factory()->create(['title' => 'Лицензия', 'image' => 'uploads/certificates/scan.jpg', 'file' => 'uploads/certificates/license.pdf', 'issuer' => 'Министерство', 'issued_at' => '2026-03-01', 'sort' => 1]);
    Certificate::factory()->create(['title' => 'Сертификат', 'image' => 'uploads/certificates/only-scan.jpg', 'file' => null, 'sort' => 2]);
    Certificate::factory()->create(['title' => 'Без файлов', 'image' => null, 'file' => null, 'sort' => 3]);
    Certificate::factory()->create(['title' => 'Скрытый документ', 'is_published' => false]);

    $this->get('/certificates')->assertOk()
        ->assertSeeInOrder(['Лицензия', 'Министерство', '01.03.2026', 'Сертификат', 'Без файлов'])
        ->assertSee('href="'.Storage::disk(config('fastleo.files.disk'))->url('uploads/certificates/license.pdf').'"', false)
        ->assertSee('href="'.Storage::disk(config('fastleo.files.disk'))->url('uploads/certificates/only-scan.jpg').'"', false)
        ->assertDontSee('Скрытый документ');
});

it('escapes the fields of a document and pages the list twenty-four at a time', function (): void {
    bootCopiedSectionSite($this, 'certificates');
    Certificate::factory()->create(['title' => '<script>alert(1)</script>', 'issuer' => '<i>x</i>']);

    $this->get('/certificates')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<i>x</i>', false);

    Certificate::factory()->count(24)->create();

    $this->get('/certificates')->assertOk()->assertSee('rel="next"', false);
    $this->get('/certificates?page=2')->assertOk()->assertSee('rel="prev"', false);
});

it('links a partner only to an address that starts with http or https, and escapes its fields', function (): void {
    bootCopiedSectionSite($this, 'partners');
    Model::shouldBeStrict();
    Partner::factory()->create(['name' => 'Ромашка', 'url' => 'https://example.com/romashka', 'description' => 'Поставщик', 'sort' => 1]);
    Partner::factory()->create(['name' => 'Лютик', 'url' => 'javascript:alert(1)', 'sort' => 2]);
    Partner::factory()->create(['name' => 'Ландыш', 'url' => '//evil.example', 'sort' => 3]);
    Partner::factory()->create(['name' => '<script>alert(1)</script>', 'url' => null, 'description' => '<i>x</i>', 'sort' => 4]);
    Partner::factory()->create(['name' => 'Скрытый партнёр', 'is_published' => false]);

    $this->get('/partners')->assertOk()
        ->assertSee('href="https://example.com/romashka" rel="noopener nofollow"', false)
        ->assertSeeInOrder(['Ромашка', 'Поставщик', 'Лютик', 'Ландыш'])
        ->assertDontSee('javascript:alert(1)', false)->assertDontSee('//evil.example', false)
        ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<i>x</i>', false)
        ->assertDontSee('Скрытый партнёр');
});

it('pages the partners forty-eight at a time and shows a text when there are none', function (): void {
    bootCopiedSectionSite($this, 'partners');

    $this->get('/partners')->assertOk()->assertSee('Партнёров пока нет.');

    Partner::factory()->count(49)->create();

    $this->get('/partners')->assertOk()->assertSee('rel="next"', false);
    $this->get('/partners?page=2')->assertOk()->assertSee('rel="prev"', false);
});

it('shows a branch with its phones, e-mail, hours and the map link, and keeps unsafe values out of the links', function (): void {
    bootCopiedSectionSite($this, 'branches');
    Model::shouldBeStrict();
    Branch::factory()->create(['name' => 'Главный офис', 'city' => 'Москва', 'address' => 'ул. Тверская, 1', 'phones' => "+7 (495) 000-00-00\nпо записи", 'email' => 'info@example.com', 'working_hours' => "Пн–Пт 9–18\n<b>Сб</b> выходной", 'latitude' => '55.7558000', 'longitude' => '37.6173000', 'sort' => 1]);
    Branch::factory()->create(['name' => 'Без координат', 'city' => null, 'address' => 'Тверская & Ко', 'phones' => null, 'email' => 'javascript:alert(1)', 'working_hours' => null, 'sort' => 2]);
    Branch::factory()->create(['name' => '<script>alert(1)</script>', 'address' => '<i>x</i>', 'sort' => 3]);
    Branch::factory()->create(['name' => 'Чужая почта', 'email' => 'x?bcc=a@example.com', 'sort' => 4]);
    Branch::factory()->create(['name' => 'Скрытый офис', 'is_published' => false]);

    $this->get('/branches')->assertOk()
        ->assertSeeInOrder(['Главный офис', 'Москва, ул. Тверская, 1', 'href="tel:+74950000000"', 'по записи', 'mailto:info@example.com', 'Пн–Пт 9–18<br />', 'Показать на карте'], false)
        ->assertSee('https://yandex.ru/maps/?pt=37.6173,55.7558&amp;z=16&amp;l=map', false)
        ->assertSee('https://yandex.ru/maps/?text='.rawurlencode('Тверская & Ко'), false)
        ->assertSee('&lt;b&gt;Сб&lt;/b&gt; выходной', false)
        ->assertSee('Чужая почта')->assertDontSee('x?bcc', false)
        ->assertDontSee('mailto:javascript', false)->assertDontSee('javascript:alert(1)', false)
        ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<i>x</i>', false)
        ->assertDontSee('Скрытый офис');
});

it('builds the phone links from the lines of the field (ten at most) and the map link from the coordinates, longitude first', function (): void {
    bootCopiedSection($this, 'branches');

    expect(Branch::factory()->make(['phones' => "+7 (495) 000-00-00\n\n  8 800 555-35-35 \nпо записи"])->phoneLinks())->toBe([
        ['text' => '+7 (495) 000-00-00', 'tel' => '+74950000000'],
        ['text' => '8 800 555-35-35', 'tel' => '88005553535'],
        ['text' => 'по записи', 'tel' => null],
    ])
        ->and(Branch::factory()->make(['phones' => "+7 (343) 000-00-01 доб. 12\nБухгалтерия: +7 912 000 00 00\n(+7) 343 000-00-01\n8 (343) 000-00-01, 8-912-000-00-00\n+7 343 000-00-01 / 000-00-02\nтел. 8 800 555-35-35"])->phoneLinks())->toBe([
            ['text' => '+7 (343) 000-00-01 доб. 12', 'tel' => '+73430000001'],
            ['text' => 'Бухгалтерия: +7 912 000 00 00', 'tel' => '+79120000000'],
            ['text' => '(+7) 343 000-00-01', 'tel' => '+73430000001'],
            ['text' => '8 (343) 000-00-01, 8-912-000-00-00', 'tel' => '83430000001'],
            ['text' => '+7 343 000-00-01 / 000-00-02', 'tel' => '+73430000001'],
            ['text' => 'тел. 8 800 555-35-35', 'tel' => '88005553535'],
        ])
        ->and(Branch::factory()->make(['phones' => implode("\n", array_fill(0, 12, '111'))])->phoneLinks())->toHaveCount(10)
        ->and(Branch::factory()->make(['phones' => null])->phoneLinks())->toBe([])
        ->and(Branch::factory()->make(['latitude' => '55.7558000', 'longitude' => '37.6173000'])->mapUrl())->toBe('https://yandex.ru/maps/?pt=37.6173,55.7558&z=16&l=map')
        ->and(Branch::factory()->make(['city' => 'Москва', 'address' => 'ул. Тверская, 1', 'latitude' => '55.7', 'longitude' => null])->mapUrl())->toBe('https://yandex.ru/maps/?text='.rawurlencode('Москва ул. Тверская, 1'))
        ->and(Branch::factory()->make(['city' => null, 'address' => 'Тверская', 'latitude' => '95', 'longitude' => '37'])->mapUrl())->toBe('https://yandex.ru/maps/?text='.rawurlencode('Тверская'))
        ->and(Branch::factory()->make(['city' => null, 'address' => ''])->mapUrl())->toBeNull();
});

it('refuses coordinates out of range and a partner link that is not http or https in the panel', function (): void {
    bootCopiedSection($this, 'branches');
    bootCopiedSection($this, 'partners');
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->post(route('fastleo.resources.store', 'branches'), ['name' => 'Офис', 'address' => 'ул. Тверская, 1', 'latitude' => '95', 'is_published' => '1'])->assertSessionHasErrors('latitude');
    $this->post(route('fastleo.resources.store', 'branches'), ['name' => 'Офис', 'address' => 'ул. Тверская, 1', 'longitude' => '181', 'is_published' => '1'])->assertSessionHasErrors('longitude');
    $this->post(route('fastleo.resources.store', 'partners'), ['name' => 'Ромашка', 'url' => 'javascript:alert(1)', 'is_published' => '1'])->assertSessionHasErrors('url');

    expect(DB::table('branches')->count())->toBe(0)->and(DB::table('partners')->count())->toBe(0);
});

it('shows a text when there are no branches', function (): void {
    bootCopiedSectionSite($this, 'branches');

    $this->get('/branches')->assertOk()->assertSee('Адресов пока нет.');
});

it('keeps the public part of reviews, portfolio, faq, services, prices, team, branches, certificates and partners off the addresses the site already uses', function (string $name, string $address): void {
    Route::get($address, fn (): string => 'свой раздел');

    $this->artisan('fastleo:module', ['modules' => [$name]])
        ->expectsOutputToContain('Публичная часть модуля '.$name.' не добавлена')
        ->expectsOutputToContain('адрес /'.$address.' уже занят')
        ->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo/'.SECTION_MODULES[$name]['resource'].'.php')->toBeFile()
        ->and(siteRoutesOf($this))->not->toContain('Controller::class');
})->with([
    'reviews' => ['reviews', 'reviews'],
    'portfolio' => ['portfolio', 'portfolio'],
    'faq' => ['faq', 'faq'],
    'services' => ['services', 'services'],
    'prices' => ['prices', 'prices'],
    'team' => ['team', 'team'],
    'branches' => ['branches', 'branches'],
    'certificates' => ['certificates', 'certificates'],
    'partners' => ['partners', 'partners'],
]);
