<?php

declare(strict_types=1);

use App\Fastleo\ContactsSettings;
use Fastleo\Installer\Module;
use Fastleo\Installer\ModuleConflictException;
use Fastleo\Installer\ModuleInstaller;
use Fastleo\Installer\Modules;
use Fastleo\Installer\ModuleWriteException;
use Fastleo\Settings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function (): void {
    // Пишем в временную папку: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-module-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox);
    File::put($this->sandbox.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
    $this->app->setBasePath($this->sandbox);
    config(['fastleo.resources.path' => $this->sandbox.'/app/Fastleo', 'fastleo.resources.namespace' => 'App\\Fastleo']);
});

afterEach(function (): void {
    File::deleteDirectory($this->sandbox);
});

/**
 * Модуль-образец с маршрутом сайта, миграцией, папками и тестом сайта: настоящих модулей с маршрутами в пакете нет.
 */
function routedModule(string $sandbox): Module
{
    $stub = $sandbox.'/stub';
    File::ensureDirectoryExists($stub.'/routes/site');
    File::ensureDirectoryExists($stub.'/app/Enums');
    File::ensureDirectoryExists($stub.'/app/Http/Controllers');
    File::ensureDirectoryExists($stub.'/database/migrations');
    File::ensureDirectoryExists($stub.'/tests/Feature/Site');
    File::ensureDirectoryExists($stub.'/fastleo');
    File::put($stub.'/routes/site/shop.php.stub', "<?php\n");
    File::put($stub.'/app/Enums/ShopStatus.php.stub', "<?php\n");
    File::put($stub.'/app/Http/Controllers/ShopController.php.stub', "<?php\n");
    File::put($stub.'/database/migrations/{timestamp}_create_shop_table.php.stub', "<?php\n");
    File::put($stub.'/tests/Feature/Site/ShopTest.php.stub', "<?php\n");
    File::put($stub.'/fastleo/ShopResource.php.stub', "<?php\n");

    return new Module('shop', 'Магазин', 'Тест', $stub, tables: ['shop'], uris: ['POST shop']);
}

it('copies the contacts module into the site as a settings page only', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['contacts']])
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

    $this->artisan('fastleo:module', ['modules' => ['contacts']])
        ->expectsOutputToContain('app/Fastleo/ContactsSettings.php уже есть')
        ->assertFailed();

    expect(File::get($this->sandbox.'/app/Fastleo/ContactsSettings.php'))->toBe('мой код');
});

it('reports a taken table and a taken address before writing anything', function (): void {
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
        expect($e->conflicts)->toHaveCount(2)
            ->and($e->conflicts[0])->toContain('таблица news')
            ->and($e->conflicts[1])->toContain('/news');
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

it('treats an address as taken only for the method the module needs', function (): void {
    app()->setLocale('ru');
    $module = routedModule($this->sandbox);
    Schema::dropIfExists('shop');
    Route::get('shop', fn (): string => 'страница');

    expect(new ModuleInstaller()->conflicts([$module]))->toBe([]);

    Route::post('shop', fn (): string => 'занято');

    expect(new ModuleInstaller()->conflicts([$module]))->toHaveCount(1)
        ->and(new ModuleInstaller()->conflicts([$module])[0])->toContain('/shop');
});

it('does not block the news module when the site has its own /news', function (): void {
    Schema::dropIfExists('news');
    Route::get('news', fn (): string => 'свои новости');
    Route::get('news/{slug}', fn (): string => 'своя новость');

    expect(new ModuleInstaller()->conflicts([Modules::find('news')]))->toBe([]);
});

it('fails on an unknown module and without names when there is no one to ask', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['blog']])
        ->expectsOutputToContain('Нет модуля «blog»')
        ->assertFailed();

    $this->artisan('fastleo:module', ['--no-interaction' => true])
        ->expectsOutputToContain('Укажите модули')
        ->assertFailed();
});

it('asks which modules to add when no name is given', function (): void {
    $options = array_map(fn (Module $module): string => $module->label.' — '.$module->description, Modules::all());

    $this->artisan('fastleo:module')
        ->expectsChoice('Какие модули добавить на сайт?', ['contacts'], $options)
        ->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo/ContactsSettings.php')->toBeFile();
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
        ->and(File::get($this->sandbox.'/app/Http'))->toBe('не папка');
});

it('removes everything it wrote, folders included, when the routes of the site cannot be changed', function (): void {
    $module = routedModule($this->sandbox);
    File::ensureDirectoryExists($this->sandbox.'/routes');
    File::put($this->sandbox.'/routes/web.php', "<?php\n");
    chmod($this->sandbox.'/routes/web.php', 0o444);

    expect(fn (): array => new ModuleInstaller()->install([$module]))->toThrow(ModuleWriteException::class);

    chmod($this->sandbox.'/routes/web.php', 0o644);

    expect($this->sandbox.'/app/Http/Controllers/ShopController.php')->not->toBeFile()
        ->and($this->sandbox.'/app')->not->toBeDirectory()
        ->and($this->sandbox.'/routes/site')->not->toBeDirectory()
        ->and($this->sandbox.'/database')->not->toBeDirectory();
});

it('does not count a test file of the site as a conflict when the tests are not going to be copied', function (): void {
    $module = routedModule($this->sandbox);
    File::ensureDirectoryExists($this->sandbox.'/tests/Feature/Site');
    File::put($this->sandbox.'/tests/Feature/Site/ShopTest.php', 'мой тест');

    new ModuleInstaller()->install([$module]);

    expect(File::get($this->sandbox.'/tests/Feature/Site/ShopTest.php'))->toBe('мой тест');
});

it('treats everything under an address as taken and adds a module once when it is named twice', function (): void {
    app()->setLocale('ru');
    Schema::dropIfExists('shop');
    Route::post('shop/{id}', fn (): string => 'раздел сайта');

    expect(new ModuleInstaller()->conflicts([routedModule($this->sandbox)])[0])->toContain('адрес /shop уже занят');

    $this->artisan('fastleo:module', ['modules' => ['contacts', 'contacts']])->assertSuccessful();
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

    $this->artisan('fastleo:module', ['modules' => ['contacts']])
        ->expectsOutputToContain('файл записать не удалось')
        ->assertFailed();

    expect(File::get($this->sandbox.'/app/Fastleo'))->toBe('не папка');
});
