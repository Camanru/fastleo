<?php

declare(strict_types=1);

use App\Fastleo\ContactsSettings;
use Fastleo\Installer\Module;
use Fastleo\Installer\ModuleConflictException;
use Fastleo\Installer\ModuleInstaller;
use Fastleo\Installer\Modules;
use Fastleo\Installer\ModuleWriteException;
use Fastleo\Settings;
use Fastleo\Support\UserModelPatcher;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function (): void {
    // Пишем в временную папку: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-module-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox);
    File::put($this->sandbox.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
    sandboxWithPest($this->sandbox);
    $this->app->setBasePath($this->sandbox);
    config(['fastleo.resources.path' => $this->sandbox.'/app/Fastleo', 'fastleo.resources.namespace' => 'App\\Fastleo']);
});

afterEach(function (): void {
    File::deleteDirectory($this->sandbox);
});

/**
 * Модуль-образец с панельной частью (миграция, enum, раздел, тест) и публичной (`site/`: маршруты, контроллер, шаблон):
 * настоящие модули пакета для проверок механизма не годятся.
 */
function routedModule(string $sandbox, bool $withSite = true): Module
{
    $stub = $sandbox.'/stub';
    File::ensureDirectoryExists($stub.'/app/Enums');
    File::ensureDirectoryExists($stub.'/database/migrations');
    File::ensureDirectoryExists($stub.'/tests/Feature/Site');
    File::ensureDirectoryExists($stub.'/fastleo');
    File::put($stub.'/app/Enums/ShopStatus.php.stub', "<?php\n");
    File::put($stub.'/database/migrations/{timestamp}_create_shop_table.php.stub', "<?php\n");
    File::put($stub.'/tests/Feature/Site/ShopTest.php.stub', "<?php\n");
    File::put($stub.'/fastleo/ShopResource.php.stub', "<?php\n");

    if ($withSite) {
        File::ensureDirectoryExists($stub.'/site/routes');
        File::ensureDirectoryExists($stub.'/site/app/Http/Controllers');
        File::ensureDirectoryExists($stub.'/site/resources/views/shop');
        File::ensureDirectoryExists($stub.'/site/tests/Feature/Fastleo');
        File::put($stub.'/site/tests/Feature/Fastleo/ShopSiteTest.php.stub', "<?php\n");
        File::put($stub.'/site/routes/web.php.stub', "\\Illuminate\\Support\\Facades\\Route::post('/shop', fn () => 'ok')->name('site.shop.index');\n");
        File::put($stub.'/site/app/Http/Controllers/ShopController.php.stub', "<?php\n");
        File::put($stub.'/site/resources/views/shop/index.blade.php.stub', 'витрина');
    }

    return new Module('shop', 'Магазин', 'Тест', $stub, tables: ['shop'], uris: ['POST shop']);
}

it('copies the contacts module into the site as a settings page only', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['contacts'], '--no-interaction' => true])
        ->expectsOutputToContain('app/Fastleo/ContactsSettings.php')
        ->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo/ContactsSettings.php')->toBeFile()
        ->and(File::get($this->sandbox.'/app/Fastleo/ContactsSettings.php'))->toContain('namespace App\Fastleo;')
        ->and($this->sandbox.'/resources')->not->toBeDirectory()
        ->and($this->sandbox.'/public')->not->toBeDirectory();

    require_once $this->sandbox.'/app/Fastleo/ContactsSettings.php';

    expect(new ContactsSettings())->toBeInstanceOf(Settings::class);
});

it('writes nothing and lists every conflict when a file is already taken', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/app/Fastleo');
    File::put($this->sandbox.'/app/Fastleo/ContactsSettings.php', 'мой код');

    $this->artisan('fastleo:module', ['modules' => ['contacts'], '--no-interaction' => true])
        ->expectsOutputToContain('app/Fastleo/ContactsSettings.php уже есть')
        ->assertFailed();

    expect(File::get($this->sandbox.'/app/Fastleo/ContactsSettings.php'))->toBe('мой код');
});

it('reports a taken table before writing anything and does not count the address of the site', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/stub/fastleo');
    File::put($this->sandbox.'/stub/fastleo/NewsResource.php.stub', '<?php // {{ namespace }}');
    Schema::create('news', function ($table): void {
        $table->id();
    });
    Route::get('news', fn (): string => 'занято');

    app()->setLocale('ru');
    $module = new Module('news', 'Новости', 'Тест', $this->sandbox.'/stub', tables: ['news'], uris: ['news']);

    try {
        new ModuleInstaller()->install([$module]);
        $this->fail('Ожидался конфликт.');
    } catch (ModuleConflictException $e) {
        expect($e->conflicts)->toHaveCount(1)
            ->and($e->conflicts[0])->toContain('таблица news');
    }

    expect($this->sandbox.'/app/Fastleo/NewsResource.php')->not->toBeFile();
});

it('reports a section address that the panel already has and writes nothing', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/stub/fastleo');
    File::put($this->sandbox.'/stub/fastleo/TeamResource.php.stub', '<?php // {{ namespace }}');
    app()->setLocale('ru');
    // Свой раздел сайта с тем же адресом уронил бы всю панель («адрес раздела совпадает»).
    $module = new Module('team', 'Команда', 'Тест', $this->sandbox.'/stub', slugs: ['users']);

    try {
        new ModuleInstaller()->install([$module]);
        $this->fail('Ожидался конфликт.');
    } catch (ModuleConflictException $e) {
        expect($e->conflicts)->toBe(['Модуль team: раздел с адресом users уже есть в панели']);
    }

    expect($this->sandbox.'/app/Fastleo/TeamResource.php')->not->toBeFile();
});

it('names the section addresses of every module the way their resources do', function (): void {
    foreach (Modules::all() as $module) {
        $declared = [];

        foreach (File::allFiles($module->directory.'/fastleo') as $file) {
            if (preg_match('/^(\w+)(Resource|Settings)\.php\.stub$/', $file->getFilename(), $name) === 1) {
                preg_match('/static \?string \$slug = \'([a-z-]+)\'/', $file->getContents(), $slug);
                $declared[] = $slug[1] ?? Str::kebab($name[2] === 'Settings' ? $name[1] : Str::pluralStudly($name[1]));
            }
        }

        sort($declared);
        $slugs = $module->slugs;
        sort($slugs);

        expect($slugs)->toBe($declared, $module->name);
    }
});

it('treats an address of the public part as taken only for the method the module needs', function (): void {
    app()->setLocale('ru');
    $module = routedModule($this->sandbox);
    Route::get('shop', fn (): string => 'страница');

    expect(new ModuleInstaller()->siteConflicts([$module]))->toBe([]);

    Route::post('shop', fn (): string => 'занято');

    expect(new ModuleInstaller()->siteConflicts([$module]))->toBe(['shop' => ['адрес /shop уже занят']]);
});

it('does not block the panel part of the news module when the site has its own /news', function (): void {
    Schema::dropIfExists('news');
    Route::get('news', fn (): string => 'свои новости');
    Route::get('news/{slug}', fn (): string => 'своя новость');

    expect(new ModuleInstaller()->conflicts([Modules::find('news')]))->toBe([]);
});

it('fails on an unknown module and without names when there is no one to ask', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['blog'], '--no-interaction' => true])
        ->expectsOutputToContain('Нет модуля «blog»')
        ->assertFailed();

    $this->artisan('fastleo:module', ['--no-interaction' => true])
        ->expectsOutputToContain('Укажите модули')
        ->assertFailed();
});

it('asks which modules to add when no name is given', function (): void {
    forgetRoute('contacts'); // демо-сайт занимает адрес /contacts: без этого вопроса про публичную часть модуля «Контакты» не было бы
    $options = array_map(fn (Module $module): string => $module->label.' — '.$module->description, Modules::all());

    $this->artisan('fastleo:module')
        ->expectsChoice('Какие модули добавить на сайт?', ['contacts'], $options)
        ->expectsConfirmation(siteQuestion(), 'no')
        ->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo/ContactsSettings.php')->toBeFile();
});

it('still asks about the public part when only one of the chosen modules cannot have it', function (): void {
    app()->setLocale('ru');
    Route::get('faq', fn (): string => 'свои вопросы');

    $this->artisan('fastleo:module', ['modules' => ['faq', 'reviews']])
        ->expectsConfirmation(siteQuestion(), 'yes')
        ->expectsOutputToContain('Публичная часть модуля faq не добавлена')
        ->assertSuccessful();

    expect(siteRoutesOf($this))->toContain("Route::get('/reviews'")->not->toContain("Route::get('/faq'")
        ->and($this->sandbox.'/app/Http/Controllers/FaqController.php')->not->toBeFile()
        ->and($this->sandbox.'/app/Fastleo/FaqResource.php')->toBeFile();
});

it('warns when a route of the site with a parameter swallows an address of the public part', function (): void {
    app()->setLocale('ru');
    Route::any('/{slug}', fn (): string => 'свой маршрут');
    $installer = new ModuleInstaller();

    $written = $installer->install([routedModule($this->sandbox)]);

    expect($installer->warnings)->toContain('Маршрут сайта /{slug} перекроет адрес /shop модуля shop: маршруты модулей дописываются в конец routes/web.php. Сузьте свой маршрут (->where(…)) или перенесите его ниже маршрутов модуля.')
        ->and(siteRoutesOf($this))->toContain("Route::post('/shop'");
});

it('does not warn about a route with a parameter that cannot match the address, nor about the fallback of the site', function (): void {
    Route::any('/{slug}', fn (): string => 'свой маршрут')->where('slug', 'about|team');
    Route::fallback(fn (): string => 'своя страница 404');
    $installer = new ModuleInstaller();

    $installer->install([routedModule($this->sandbox)]);

    expect($installer->warnings)->toBe([]);
});

it('warns that the home page of the pages module will not open while the site keeps its own route for the root', function (): void {
    app()->setLocale('ru');
    Route::view('/', 'welcome');
    $module = routedModule($this->sandbox);
    $home = new Module('shop', 'Магазин', 'Тест', $module->directory, tables: ['shop'], uris: ['POST shop'], takesHome: true);
    $installer = new ModuleInstaller();

    $installer->install([$home]);

    expect($installer->warnings)->toContain('У сайта свой маршрут для «/»: главная страница модуля «Страницы» не откроется, пока вы его не уберёте.');
});

it('shows the paths of the written files without the base path even when it repeats inside the path', function (): void {
    $base = $this->sandbox.'/app';
    File::ensureDirectoryExists($base);
    File::put($base.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
    sandboxWithPest($base);
    $this->app->setBasePath($base);
    config(['fastleo.resources.path' => $base.$base]);

    $written = new ModuleInstaller()->install([routedModule($this->sandbox, withSite: false)]);

    expect($written)->toContain(ltrim($base, '/').'/ShopResource.php');
});

it('removes the files it already wrote when a folder cannot be created', function (): void {
    $module = routedModule($this->sandbox);
    File::ensureDirectoryExists($this->sandbox.'/app');
    File::put($this->sandbox.'/app/Http', 'не папка');

    expect(fn (): array => new ModuleInstaller()->install([$module]))->toThrow(ModuleWriteException::class);

    expect($this->sandbox.'/app/Enums/ShopStatus.php')->not->toBeFile()
        ->and($this->sandbox.'/app/Fastleo/ShopResource.php')->not->toBeFile()
        ->and($this->sandbox.'/app/Enums')->not->toBeDirectory()
        ->and($this->sandbox.'/app/Fastleo')->not->toBeDirectory()
        ->and($this->sandbox.'/resources')->not->toBeDirectory()
        ->and(File::get($this->sandbox.'/app/Http'))->toBe('не папка');
});

it('keeps the panel part and skips the public part when routes/web.php cannot be written', function (): void {
    app()->setLocale('ru');
    $module = routedModule($this->sandbox);
    File::ensureDirectoryExists($this->sandbox.'/routes');
    File::put($this->sandbox.'/routes/web.php', "<?php\n");
    chmod($this->sandbox.'/routes/web.php', 0o444);
    $installer = new ModuleInstaller();

    $written = $installer->install([$module]);

    chmod($this->sandbox.'/routes/web.php', 0o644);

    expect($installer->siteSkipped)->toBe(['shop' => ['файл routes/web.php недоступен для записи']])
        ->and($written)->toContain('app/Fastleo/ShopResource.php')
        ->and(siteRoutesOf($this))->not->toContain("Route::post('/shop'")
        ->and($this->sandbox.'/resources')->not->toBeDirectory();
});

it('counts a test file of the site that has the name of a test of the module as a conflict and keeps it', function (): void {
    app()->setLocale('ru');
    $module = routedModule($this->sandbox);
    File::ensureDirectoryExists($this->sandbox.'/tests/Feature/Site');
    File::put($this->sandbox.'/tests/Feature/Site/ShopTest.php', 'мой тест');

    expect(new ModuleInstaller()->conflicts([$module]))->toContain('Модуль shop: файл tests/Feature/Site/ShopTest.php уже есть');

    File::ensureDirectoryExists($this->sandbox.'/tests/Feature/Other');
    File::put($this->sandbox.'/tests/Feature/Other/ShopTest.php', 'другой тест');
    File::delete($this->sandbox.'/tests/Feature/Site/ShopTest.php');
    new ModuleInstaller()->install([$module]);

    expect(File::get($this->sandbox.'/tests/Feature/Other/ShopTest.php'))->toBe('другой тест')
        ->and($this->sandbox.'/tests/Feature/Site/ShopTest.php')->toBeFile();
});

it('treats everything under an address as taken and adds a module once when it is named twice', function (): void {
    app()->setLocale('ru');
    Route::post('shop/{id}', fn (): string => 'раздел сайта');

    expect(new ModuleInstaller()->siteConflicts([routedModule($this->sandbox)])['shop'][0])->toContain('адрес /shop уже занят');

    $this->artisan('fastleo:module', ['modules' => ['contacts', 'contacts'], '--no-interaction' => true])->assertSuccessful();
});

it('does not fail on a table check when the database is unavailable', function (): void {
    Schema::shouldReceive('hasTable')->andThrow(new RuntimeException('нет базы'));
    $module = new Module('x', 'X', 'Тест', Modules::find('contacts')->directory, tables: ['x']);

    expect(new ModuleInstaller()->conflicts([$module]))->toBe([]);
});

it('passes on the note of a module and prints it with the result of the installation', function (): void {
    $module = new Module('shop', 'Магазин', 'Тест', routedModule($this->sandbox)->directory, note: 'Заметка модуля');
    $installer = new ModuleInstaller();

    $installer->install([$module]);

    expect($installer->notes)->toContain('Заметка модуля');
});

it('reports a folder that cannot be created and writes nothing', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/app');
    File::put($this->sandbox.'/app/Fastleo', 'не папка');

    $this->artisan('fastleo:module', ['modules' => ['contacts'], '--no-interaction' => true])
        ->expectsOutputToContain('файл записать не удалось')
        ->assertFailed();

    expect(File::get($this->sandbox.'/app/Fastleo'))->toBe('не папка');
});

it('writes the public part with the shared layout and connects the routes', function (): void {
    app()->setLocale('ru');
    $installer = new ModuleInstaller();

    $written = $installer->install([routedModule($this->sandbox)]);

    expect($written)->toContain('app/Http/Controllers/ShopController.php', 'resources/views/shop/index.blade.php', 'resources/views/layouts/site.blade.php')
        ->and($written)->not->toContain('routes/web.php')
        ->and(File::get($this->sandbox.'/resources/views/layouts/site.blade.php'))->toContain("@yield('content')")
        ->and(siteRoutesOf($this))->toContain("\\Illuminate\\Support\\Facades\\Route::post('/shop'")->not->toContain('fastleo')
        ->and($installer->notes)->toBe(['routes/web.php: в конец дописаны маршруты модулей: shop (блок «Страниц» остаётся последним среди маршрутов модулей). Комментариев в файл не добавляется: модуль убирают, удалив его маршруты (имена site.<модуль>.*, их видно в php artisan route:list --name=site)'])
        ->and($installer->siteSkipped)->toBe([])
        ->and($installer->warnings)->toBe([]);
});

it('writes only the panel part when the public part is not wanted', function (): void {
    $written = new ModuleInstaller()->install([routedModule($this->sandbox)], withSite: false);

    expect($written)->toContain('app/Fastleo/ShopResource.php')
        ->and($this->sandbox.'/resources')->not->toBeDirectory()
        ->and($this->sandbox.'/routes')->not->toBeDirectory();
});

it('keeps the templates and the layout of the site and warns about them', function (): void {
    app()->setLocale('ru');
    File::ensureDirectoryExists($this->sandbox.'/resources/views/shop');
    File::ensureDirectoryExists($this->sandbox.'/resources/views/layouts');
    File::put($this->sandbox.'/resources/views/shop/index.blade.php', 'моя витрина');
    File::put($this->sandbox.'/resources/views/layouts/site.blade.php', "<main>@yield('content')</main>");
    $installer = new ModuleInstaller();

    $written = $installer->install([routedModule($this->sandbox)]);

    expect(File::get($this->sandbox.'/resources/views/shop/index.blade.php'))->toBe('моя витрина')
        ->and(File::get($this->sandbox.'/resources/views/layouts/site.blade.php'))->toBe("<main>@yield('content')</main>")
        ->and($written)->not->toContain('resources/views/shop/index.blade.php')
        ->and($installer->warnings)->toBe(['Оставлен ваш шаблон resources/views/shop/index.blade.php: шаблон модуля не записан']);
});

it('warns when the layout of the site cannot show the pages of a module', function (string $layout): void {
    app()->setLocale('ru');
    File::ensureDirectoryExists($this->sandbox.'/resources/views/layouts');
    File::put($this->sandbox.'/resources/views/layouts/site.blade.php', $layout);
    $installer = new ModuleInstaller();

    $installer->install([routedModule($this->sandbox)]);

    expect($installer->warnings)->toHaveCount(1)
        ->and($installer->warnings[0])->toContain('resources/views/layouts/site.blade.php')
        ->and($installer->warnings[0])->toContain('нет @yield("content")');
})->with([
    'empty' => '<main></main>',
    'slot of a component, the templates of modules extend the layout' => '<main>{{ $slot }}</main>',
]);

it('skips the public part of one module and installs the rest', function (): void {
    app()->setLocale('ru');
    $shop = routedModule($this->sandbox);
    $blog = new Module('blog', 'Блог', 'Тест', $this->sandbox.'/blog', uris: ['blog']);
    File::ensureDirectoryExists($blog->directory.'/site/routes');
    File::put($blog->directory.'/site/routes/web.php.stub', "\\Illuminate\\Support\\Facades\\Route::get('/blog', fn () => 'ok');\n");
    Route::get('blog', fn (): string => 'свой блог');
    $installer = new ModuleInstaller();

    $written = $installer->install([$shop, $blog]);

    expect($installer->siteSkipped)->toBe(['blog' => ['адрес /blog уже занят']])
        ->and(siteRoutesOf($this))->toContain("Route::post('/shop'")->not->toContain("Route::get('/blog'");
});

it('does not append the routes of a module twice: the block that is already in routes/web.php skips the public part', function (): void {
    app()->setLocale('ru');
    $shop = routedModule($this->sandbox);
    new ModuleInstaller()->install([$shop]);
    // Остался только блок маршрутов: контроллер и тест публичной части, например, удалили.
    File::delete($this->sandbox.'/app/Http/Controllers/ShopController.php');
    File::delete($this->sandbox.'/tests/Feature/Fastleo/ShopSiteTest.php');
    $routes = siteRoutesOf($this);

    expect(new ModuleInstaller()->siteConflicts([$shop]))->toBe(['shop' => ['в routes/web.php уже есть маршруты модуля shop']])
        ->and(substr_count($routes, "Route::post('/shop'"))->toBe(1);
});

it('skips the public part of a module when the name of one of its routes is already taken', function (): void {
    app()->setLocale('ru');
    Route::get('/elsewhere', fn (): string => 'своё')->name('site.shop.index');
    Route::getRoutes()->refreshNameLookups();

    expect(new ModuleInstaller()->siteConflicts([routedModule($this->sandbox)]))->toBe(['shop' => ['имя маршрута site.shop.index уже занято']]);
});

it('writes no layout and no routes when the public part is skipped everywhere', function (): void {
    Route::post('shop', fn (): string => 'занято');
    $installer = new ModuleInstaller();

    $written = $installer->install([routedModule($this->sandbox)]);

    expect($written)->toContain('app/Fastleo/ShopResource.php')
        ->and($installer->siteSkipped)->toHaveCount(1)
        ->and($this->sandbox.'/resources')->not->toBeDirectory()
        ->and($this->sandbox.'/routes')->not->toBeDirectory();
});

it('reports a taken file of the public part but not a template of the site', function (): void {
    app()->setLocale('ru');
    File::ensureDirectoryExists($this->sandbox.'/app/Http/Controllers');
    File::put($this->sandbox.'/app/Http/Controllers/ShopController.php', 'мой контроллер');
    File::ensureDirectoryExists($this->sandbox.'/resources/views/shop');
    File::put($this->sandbox.'/resources/views/shop/index.blade.php', 'моя витрина');

    expect(new ModuleInstaller()->siteConflicts([routedModule($this->sandbox)]))->toBe(['shop' => ['файл app/Http/Controllers/ShopController.php уже есть']]);
});

it('reports the panel on the root of the domain as a conflict of the public part', function (): void {
    app()->setLocale('ru');
    config(['fastleo.path' => '', 'fastleo.domain' => null]);

    expect(new ModuleInstaller()->siteConflicts([routedModule($this->sandbox)])['shop'][0])->toContain('панель открыта на корне домена');

    config(['fastleo.domain' => 'admin.site.ru']);

    expect(new ModuleInstaller()->siteConflicts([routedModule($this->sandbox)]))->toBe([]);
});

it('reports an existing fallback route as a conflict only for a module that takes the fallback', function (): void {
    app()->setLocale('ru');
    Route::fallback(fn (): string => 'своя страница 404');
    $directory = routedModule($this->sandbox)->directory;

    expect(new ModuleInstaller()->siteConflicts([new Module('shop', 'Магазин', 'Тест', $directory)]))->toBe([])
        ->and(new ModuleInstaller()->siteConflicts([new Module('shop', 'Магазин', 'Тест', $directory, takesFallback: true)]))
        ->toBe(['shop' => ['на сайте уже есть запасной маршрут (Route::fallback): второй перекрыл бы его']]);
});

it('has no public part to conflict with in a module without a site folder', function (): void {
    Route::fallback(fn (): string => 'своя страница 404');
    $module = routedModule($this->sandbox, withSite: false);

    expect(new ModuleInstaller()->siteConflicts([new Module('shop', 'Магазин', 'Тест', $module->directory, uris: ['shop'], takesFallback: true)]))->toBe([]);
});

it('prints the note of the public part only when the public part is written', function (): void {
    $directory = routedModule($this->sandbox)->directory;
    $module = new Module('shop', 'Магазин', 'Тест', $directory, siteNote: 'Заметка публичной части');

    $installer = new ModuleInstaller();
    $installer->install([$module]);

    expect($installer->notes)->toContain('Заметка публичной части');

    File::deleteDirectory($this->sandbox.'/app');
    File::deleteDirectory($this->sandbox.'/database');
    File::deleteDirectory($this->sandbox.'/tests/Feature');

    $installer->install([$module], withSite: false);

    expect($installer->notes)->not->toContain('Заметка публичной части');
});

it('reminds to clear the route cache after the public part is written', function (): void {
    app()->setLocale('ru');
    $this->app->instance('routes.cached', true);
    $installer = new ModuleInstaller();

    $installer->install([routedModule($this->sandbox)]);

    expect($installer->notes)->toContain('Маршруты сайта закешированы: выполните php artisan route:clear, иначе публичная часть не заработает.');
});

it('replaces the untouched welcome route when the module takes the home page', function (): void {
    app()->setLocale('ru');
    File::ensureDirectoryExists($this->sandbox.'/routes');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nRoute::get('/', function () {\n    return view('welcome');\n});\n");
    $directory = routedModule($this->sandbox)->directory;
    $installer = new ModuleInstaller();

    $installer->install([new Module('shop', 'Магазин', 'Тест', $directory, takesHome: true)]);

    expect(File::get($this->sandbox.'/routes/web.php'))->not->toContain("view('welcome')")
        ->and($installer->notes)->toContain('routes/web.php: убран пример главной страницы Laravel (welcome) — главную теперь показывает модуль «Страницы»');
});

it('keeps the untouched welcome route when the module does not take the home page', function (): void {
    app()->setLocale('ru');
    File::ensureDirectoryExists($this->sandbox.'/routes');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nRoute::get('/', function () {\n    return view('welcome');\n});\n");
    $directory = routedModule($this->sandbox)->directory;
    $installer = new ModuleInstaller();

    $installer->install([new Module('shop', 'Магазин', 'Тест', $directory)]);

    expect(File::get($this->sandbox.'/routes/web.php'))->toContain("view('welcome')")->toContain("Route::post('/shop'")
        ->and(implode("\n", $installer->notes))->not->toContain('welcome');
});

it('refuses to install a module and writes nothing when the site has no Pest', function (): void {
    File::delete($this->sandbox.'/tests/Pest.php');

    $this->artisan('fastleo:module', ['modules' => ['contacts'], '--no-interaction' => true])
        ->expectsOutputToContain('Модулям нужен Pest')
        ->assertFailed();

    expect($this->sandbox.'/app/Fastleo')->not->toBeDirectory()
        ->and($this->sandbox.'/tests/Feature')->not->toBeDirectory();

    File::put($this->sandbox.'/tests/Pest.php', "<?php\n");
    File::put($this->sandbox.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');

    $this->artisan('fastleo:module', ['modules' => ['contacts'], '--no-interaction' => true])->assertFailed();

    expect($this->sandbox.'/app/Fastleo')->not->toBeDirectory();
});

it('asks for Pest before the list of modules is shown', function (): void {
    File::delete($this->sandbox.'/tests/Pest.php');

    $this->artisan('fastleo:module')->expectsOutputToContain('Модулям нужен Pest')->assertFailed();
});

it('tells that the application namespace is unknown instead of failing with a trace', function (): void {
    File::put($this->sandbox.'/composer.json', '{"require-dev": {"pestphp/pest": "^4.0"}}');

    $this->artisan('fastleo:module', ['modules' => ['news'], '--no-interaction' => true])
        ->expectsOutputToContain('Не удалось определить пространство имён приложения')
        ->assertFailed();

    expect($this->sandbox.'/app/Models/News.php')->not->toBeFile();
});

it('warns when routes/web.php is not connected in bootstrap/app.php', function (): void {
    app()->setLocale('ru');
    File::ensureDirectoryExists($this->sandbox.'/bootstrap');
    File::put($this->sandbox.'/bootstrap/app.php', "<?php\n\nreturn Application::configure()->withRouting(api: __DIR__.'/../routes/api.php')->create();\n");
    $installer = new ModuleInstaller();

    $installer->install([routedModule($this->sandbox)]);

    expect($installer->warnings)->toContain('Не найдено подключение routes/web.php в bootstrap/app.php (withRouting(web: …)): маршруты модулей не заработают, пока файл не подключён.');

    File::put($this->sandbox.'/bootstrap/app.php', "<?php\n\nreturn Application::configure()->withRouting(web: __DIR__.'/../routes/web.php')->create();\n");
    File::deleteDirectory($this->sandbox.'/app');
    File::deleteDirectory($this->sandbox.'/database');
    File::deleteDirectory($this->sandbox.'/tests/Feature');
    File::delete($this->sandbox.'/routes/web.php');
    File::deleteDirectory($this->sandbox.'/resources');
    $again = new ModuleInstaller();

    $again->install([routedModule($this->sandbox)]);

    expect($again->warnings)->toBe([]);
});

it('warns that a route of the site with two parameters swallows the pages of the records of a module', function (): void {
    app()->setLocale('ru');
    Route::get('/{category}/{slug}', fn (): string => 'свой маршрут');
    $installer = new ModuleInstaller();

    $installer->install([Modules::find('news'), Modules::find('reviews')]);

    expect($installer->warnings)->toContain('Маршрут сайта /{category}/{slug} перекроет адрес /news/{slug} модуля news: маршруты модулей дописываются в конец routes/web.php. Сузьте свой маршрут (->where(…)) или перенесите его ниже маршрутов модуля.')
        ->and(implode("\n", $installer->warnings))->not->toContain('модуля reviews');
});

it('reports the test of the public part as a conflict when Pest is installed', function (): void {
    app()->setLocale('ru');
    File::put($this->sandbox.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}, "require-dev": {"pestphp/pest": "^4.0"}}');
    File::ensureDirectoryExists($this->sandbox.'/tests/Feature/Fastleo');
    File::put($this->sandbox.'/tests/Pest.php', "<?php\n");
    File::put($this->sandbox.'/tests/Feature/Fastleo/ShopSiteTest.php', 'мой тест');

    expect(new ModuleInstaller()->siteConflicts([routedModule($this->sandbox)]))->toBe(['shop' => ['файл tests/Feature/Fastleo/ShopSiteTest.php уже есть']]);
});

it('keeps the code of every module compilable: PHP files and Blade templates of the panel and the public part', function (): void {
    $failed = [];
    $checked = 0;

    foreach (File::allFiles(dirname(__DIR__, 3).'/stubs/modules', true) as $file) {
        $code = strtr($file->getContents(), ['{{ namespace }}' => 'App\\Fastleo', '{{ appNamespace }}' => 'App']);

        // Заготовка маршрутов — код без `<?php`: он дописывается в `routes/web.php`.
        if ($file->getFilename() === 'web.php.stub') {
            $code = "<?php\n".$code;
        }

        if (str_ends_with($file->getFilename(), '.blade.php.stub')) {
            $code = Blade::compileString($code);
        } elseif (! str_ends_with($file->getFilename(), '.php.stub')) {
            continue;
        }

        $checked++;

        if (! UserModelPatcher::compiles($code)) {
            $failed[] = $file->getRelativePathname();
        }
    }

    expect($failed)->toBe([])
        ->and($checked)->toBeGreaterThan(40);
});
