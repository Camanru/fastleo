<?php

declare(strict_types=1);

use App\Fastleo\ContactsSettings;
use Fastleo\Installer\Module;
use Fastleo\Installer\ModuleConflictException;
use Fastleo\Installer\ModuleInstaller;
use Fastleo\Installer\Modules;
use Fastleo\Settings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

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

it('copies the contacts module into the site as a settings page only', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['contacts']])
        ->expectsOutputToContain('app/Fastleo/ContactsSettings.php')
        ->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo/ContactsSettings.php')->toBeFile()
        ->and(File::get($this->sandbox.'/app/Fastleo/ContactsSettings.php'))->toContain('namespace App\Fastleo;')
        ->and($this->sandbox.'/resources')->not->toBeDirectory()
        ->and($this->sandbox.'/public')->not->toBeDirectory();

    require_once $this->sandbox.'/app/Fastleo/ContactsSettings.php';

    expect(new ContactsSettings)->toBeInstanceOf(Settings::class);
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

it('treats an address as taken only for the method the module needs', function (): void {
    app()->setLocale('ru');
    Schema::dropIfExists('leads');
    Route::get('leads', fn (): string => 'страница');

    expect(new ModuleInstaller()->conflicts([Modules::find('leads')]))->toBe([]);

    Route::post('leads', fn (): string => 'занято');

    expect(new ModuleInstaller()->conflicts([Modules::find('leads')]))->toHaveCount(1)
        ->and(new ModuleInstaller()->conflicts([Modules::find('leads')])[0])->toContain('/leads');
});

it('does not block the news module when the site has its own /news', function (): void {
    Schema::dropIfExists('news');
    Route::get('news', fn (): string => 'свои новости');
    Route::get('news/{slug}', fn (): string => 'своя новость');

    expect(new ModuleInstaller()->conflicts([Modules::find('news')]))->toBe([]);
});

it('fails on an unknown module and without names when there is no one to ask', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['shop']])
        ->expectsOutputToContain('Нет модуля «shop»')
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
    File::ensureDirectoryExists($this->sandbox.'/app');
    File::put($this->sandbox.'/app/Http', 'не папка');

    $this->artisan('fastleo:module', ['modules' => ['leads']])
        ->expectsOutputToContain('файл записать не удалось')
        ->assertFailed();

    expect($this->sandbox.'/app/Enums/LeadStatus.php')->not->toBeFile()
        ->and($this->sandbox.'/app/Fastleo/LeadResource.php')->not->toBeFile()
        ->and($this->sandbox.'/app/Enums')->not->toBeDirectory()
        ->and($this->sandbox.'/app/Fastleo')->not->toBeDirectory()
        ->and(File::get($this->sandbox.'/app/Http'))->toBe('не папка');
});

it('removes everything it wrote, folders included, when the routes of the site cannot be changed', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/routes');
    File::put($this->sandbox.'/routes/web.php', "<?php\n");
    chmod($this->sandbox.'/routes/web.php', 0o444);

    $this->artisan('fastleo:module', ['modules' => ['leads']])
        ->expectsOutputToContain('файл записать не удалось')
        ->assertFailed();

    chmod($this->sandbox.'/routes/web.php', 0o644);

    expect($this->sandbox.'/app/Models/Lead.php')->not->toBeFile()
        ->and($this->sandbox.'/app')->not->toBeDirectory()
        ->and($this->sandbox.'/routes/site')->not->toBeDirectory()
        ->and($this->sandbox.'/database')->not->toBeDirectory();
});

it('does not count a test file of the site as a conflict when the tests are not going to be copied', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/tests/Feature/Site');
    File::put($this->sandbox.'/tests/Feature/Site/LeadsTest.php', 'мой тест');

    $this->artisan('fastleo:module', ['modules' => ['leads']])->assertSuccessful();

    expect(File::get($this->sandbox.'/tests/Feature/Site/LeadsTest.php'))->toBe('мой тест');
});

it('treats everything under an address as taken and adds a module once when it is named twice', function (): void {
    Route::post('leads/{id}', fn (): string => 'раздел сайта');

    $this->artisan('fastleo:module', ['modules' => ['leads']])
        ->expectsOutputToContain('адрес /leads уже занят')
        ->assertFailed();

    $this->artisan('fastleo:module', ['modules' => ['contacts', 'contacts']])->assertSuccessful();
});

it('does not fail on a table check when the database is unavailable', function (): void {
    Schema::shouldReceive('hasTable')->andThrow(new RuntimeException('нет базы'));
    $module = new Module('x', 'X', 'Тест', Modules::find('contacts')->directory, tables: ['x']);

    expect(new ModuleInstaller()->conflicts([$module]))->toBe([]);
});
