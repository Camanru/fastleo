<?php

declare(strict_types=1);

use App\FastleoSections\ContactsSettings;
use App\FastleoSections\Models\FaqItem;
use App\FastleoSections\Models\MenuItem;
use App\FastleoSections\Models\PortfolioItem;
use App\FastleoSections\Models\Review;
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

/**
 * Модули «Меню сайта», «Отзывы», «Портфолио», «Вопросы и ответы»: код копируется в сайт и поднимается по-настоящему.
 */
const SECTION_MODULES = [
    'menu' => ['table' => 'menu_items', 'slug' => 'menu-items', 'resource' => 'MenuItemResource', 'model' => 'MenuItem', 'store' => ['title' => 'Контакты', 'url' => '/contacts', 'location' => 'header', 'is_published' => '1']],
    'reviews' => ['table' => 'reviews', 'slug' => 'reviews', 'resource' => 'ReviewResource', 'model' => 'Review', 'store' => ['author' => 'Анна', 'rating' => '5', 'body' => 'Спасибо!', 'is_published' => '1']],
    'portfolio' => ['table' => 'portfolio_items', 'slug' => 'portfolio', 'resource' => 'PortfolioResource', 'model' => 'PortfolioItem', 'store' => ['title' => 'Работа', 'slug' => 'rabota', 'is_published' => '1']],
    'faq' => ['table' => 'faq_items', 'slug' => 'faq', 'resource' => 'FaqResource', 'model' => 'FaqItem', 'store' => ['question' => 'Как заказать?', 'answer' => '<p>Позвоните.</p>', 'is_published' => '1']],
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

    foreach (['app/Models/'.$module['model'] => 'App\\FastleoSections\\Models\\'.$module['model'], 'app/Fastleo/'.$module['resource'] => 'App\\FastleoSections\\'.$module['resource'], 'database/factories/'.$module['model'].'Factory' => 'Database\\Factories\\'.$module['model'].'Factory'] as $file => $class) {
        if (! class_exists($class, false)) {
            require $test->sandbox.'/'.$file.'.php';
        }
    }

    Fastleo::resources()->flush();
}

it('is in the list of modules with its own tables', function (): void {
    expect(array_keys(Modules::all()))->toBe(['contacts', 'pages', 'news', 'menu', 'reviews', 'portfolio', 'faq', 'shop'])
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

it('puts the sections of the modules in the top of the menu, without a group (the shop has its own)', function (): void {
    $stubs = array_values(array_filter(glob(dirname(__DIR__, 3).'/stubs/modules/*/fastleo/*Resource.php.stub'), fn (string $stub): bool => ! str_contains($stub, '/shop/')));

    expect($stubs)->toHaveCount(6);

    foreach ($stubs as $stub) {
        expect(File::get($stub))->not->toContain('$group');
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
    $controller = ['reviews' => 'ReviewController', 'portfolio' => 'PortfolioController', 'faq' => 'FaqController'][$name];

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

it('keeps the public part of reviews, portfolio and faq off the addresses the site already uses', function (string $name, string $address): void {
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
]);
