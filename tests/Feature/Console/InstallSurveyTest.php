<?php

declare(strict_types=1);

use Fastleo\FastleoServiceProvider;
use Fastleo\Installer\Module;
use Fastleo\Installer\Modules;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Models\User;

beforeEach(function (): void {
    // Пишем в временную папку: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-survey-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/routes');
    File::ensureDirectoryExists($this->sandbox.'/public/storage');
    File::ensureDirectoryExists($this->sandbox.'/config');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
    File::put($this->sandbox.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
    sandboxWithPest($this->sandbox);
    $this->app->setBasePath($this->sandbox);
    Schema::dropIfExists('pages'); // таблица демо-сайта: модуль не ставится поверх чужой
    config([
        'fastleo.resources.path' => $this->sandbox.'/app/Fastleo',
        'fastleo.resources.namespace' => 'App\\Fastleo',
        'filesystems.links' => [$this->sandbox.'/public/storage' => $this->sandbox.'/storage/app/public'],
    ]);
    new FastleoServiceProvider($this->app)->boot();
    $this->base = ['--skill-path' => $this->sandbox.'/skill'];
});

afterEach(function (): void {
    File::deleteDirectory($this->sandbox);
});

it('adds the listed modules without questions and leaves the setup steps to the owner', function (): void {
    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--modules' => 'contacts, news'])
        ->expectsOutputToContain('app/Fastleo/ContactsSettings.php')
        ->expectsOutputToContain('php artisan fastleo:user')
        ->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo/ContactsSettings.php')->toBeFile()
        ->and($this->sandbox.'/app/Fastleo/NewsResource.php')->toBeFile()
        ->and(glob($this->sandbox.'/database/migrations/*_create_news_table.php'))->toHaveCount(1)
        ->and(Schema::hasTable('news'))->toBeFalse();
});

it('adds no modules with --type=basic or --type=custom and no module names', function (): void {
    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--type' => 'basic'])->assertSuccessful();
    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--type' => 'custom'])->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo')->not->toBeDirectory();
});

it('goes straight to the steps after the basic installation without asking about modules', function (): void {
    $this->artisan('fastleo:install', $this->base)
        ->expectsChoice('Какая установка?', 'basic', Modules::types())
        ->expectsConfirmation('Создать первого пользователя сейчас?', 'no')
        ->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo')->not->toBeDirectory();
});

it('asks for the modules at once when --type=custom is given', function (): void {
    forgetRoute('contacts'); // демо-сайт занимает адрес /contacts: без этого вопроса про публичную часть модуля «Контакты» не было бы
    $modules = array_map(fn (Module $module): string => $module->label.' — '.$module->description, Modules::all());

    $this->artisan('fastleo:install', [...$this->base, '--type' => 'custom'])
        ->expectsChoice('Что будет на сайте? (пробел — отметить, Enter — дальше)', ['contacts'], $modules)
        ->expectsConfirmation(siteQuestion(), 'yes')
        ->expectsConfirmation('Выполнить миграции сейчас?', 'no')
        ->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo/ContactsSettings.php')->toBeFile();
});

it('adds no modules with --no-modules or when nothing is asked and nobody can answer', function (): void {
    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--no-modules' => true])->assertSuccessful();
    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true])->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo')->not->toBeDirectory();
});

it('refuses a module list without a single name and writes nothing', function (string $list): void {
    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--modules' => $list])
        ->expectsOutputToContain('В --modules нет ни одного названия модуля')
        ->assertFailed();

    expect($this->sandbox.'/app/Fastleo')->not->toBeDirectory();
})->with([',', ' , ,']);

it('refuses contradictory flags and writes nothing', function (array $flags): void {
    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, ...$flags])
        ->expectsOutputToContain('Флаги противоречат друг другу')
        ->assertFailed();

    expect($this->sandbox.'/app/Fastleo')->not->toBeDirectory();
})->with([
    'basic with modules' => [['--type' => 'basic', '--modules' => 'contacts']],
    'no modules with modules' => [['--no-modules' => true, '--modules' => 'contacts']],
    'no modules with custom' => [['--no-modules' => true, '--type' => 'custom']],
]);

it('rejects an unknown site type and an unknown module', function (): void {
    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--type' => 'shop'])
        ->expectsOutputToContain('Нет вида установки «shop». Доступно: basic, custom')
        ->assertFailed();

    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--modules' => 'blog'])
        ->expectsOutputToContain('Нет модуля «blog»')
        ->assertFailed();
});

it('refuses the modules before anything is chosen or written when the site has no Pest', function (): void {
    File::delete($this->sandbox.'/tests/Pest.php');

    $this->artisan('fastleo:install', ['--modules' => 'contacts', '--no-interaction' => true])
        ->expectsOutputToContain('Модулям нужен Pest')
        ->assertFailed();

    expect($this->sandbox.'/app/Fastleo/ContactsSettings.php')->not->toBeFile();

    $this->artisan('fastleo:install', ['--type' => 'custom'])
        ->expectsOutputToContain('Модулям нужен Pest')
        ->assertFailed();
});

it('does not ask for Pest when no module is wanted', function (): void {
    File::delete($this->sandbox.'/tests/Pest.php');

    $this->artisan('fastleo:install', ['--no-modules' => true, '--no-interaction' => true])->assertSuccessful();
});

it('stops with the list of conflicts and writes no module when a place is taken', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/app/Fastleo');
    File::put($this->sandbox.'/app/Fastleo/ContactsSettings.php', 'мой код');

    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--modules' => 'contacts,news'])
        ->expectsOutputToContain('ContactsSettings.php уже есть')
        ->assertFailed();

    expect($this->sandbox.'/app/Fastleo/NewsResource.php')->not->toBeFile();
});

it('runs the setup steps by flags: migrate, examples, administrator', function (): void {
    // Как composer на сайте: App\\ — папка app, Database\\Seeders\\ — database/seeders.
    spl_autoload_register($loader = function (string $class): void {
        $file = match (true) {
            str_starts_with($class, 'Database\\Seeders\\') => $this->sandbox.'/database/seeders/'.str_replace('\\', '/', substr($class, 17)).'.php',
            str_starts_with($class, 'Database\\Factories\\') => $this->sandbox.'/database/factories/'.str_replace('\\', '/', substr($class, 19)).'.php',
            str_starts_with($class, 'App\\') => $this->sandbox.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php',
            default => '',
        };

        if (is_file($file)) {
            require_once $file;
        }
    });

    $this->artisan('fastleo:install', [
        ...$this->base,
        '--no-interaction' => true,
        '--modules' => 'news',
        '--setup' => true,
        '--seed' => true,
        '--admin-email' => 'owner@example.com',
        '--admin-password' => 'secret-pass-1',
    ])->assertSuccessful();

    spl_autoload_unregister($loader);

    expect(Schema::hasTable('news'))->toBeTrue()
        ->and(DB::table('news')->count())->toBe(3)
        ->and(User::query()->where('email', 'owner@example.com')->value('fastleo_admin'))->toBeTruthy();
});

it('asks for the site type and the modules, then for each step', function (): void {
    forgetRoute('contacts'); // демо-сайт занимает адрес /contacts: без этого вопроса про публичную часть модуля «Контакты» не было бы
    $types = Modules::types();
    $modules = array_map(fn (Module $module): string => $module->label.' — '.$module->description, Modules::all());

    $this->artisan('fastleo:install', $this->base)
        ->expectsChoice('Какая установка?', 'custom', $types)
        ->expectsChoice('Что будет на сайте? (пробел — отметить, Enter — дальше)', ['contacts'], $modules)
        ->expectsConfirmation(siteQuestion(), 'yes')
        ->expectsConfirmation('Выполнить миграции сейчас?', 'yes')
        ->expectsConfirmation('Создать первого пользователя сейчас?', 'no')
        ->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo/ContactsSettings.php')->toBeFile();
});

it('does not ask about examples or the administrator when the migrations were declined', function (): void {
    $types = Modules::types();
    $modules = array_map(fn (Module $module): string => $module->label.' — '.$module->description, Modules::all());

    $this->artisan('fastleo:install', $this->base)
        ->expectsChoice('Какая установка?', 'custom', $types)
        ->expectsChoice('Что будет на сайте? (пробел — отметить, Enter — дальше)', ['news'], $modules)
        ->expectsConfirmation(siteQuestion(), 'yes')
        ->expectsConfirmation('Выполнить миграции сейчас?', 'no')
        ->expectsOutputToContain('php artisan fastleo:user')
        ->assertSuccessful();

    expect(Schema::hasTable('news'))->toBeFalse();
});

it('shows the conflicts of the chosen modules and asks again without them', function (): void {
    forgetRoute('contacts'); // демо-сайт занимает адрес /contacts: без этого вопроса про публичную часть модуля «Контакты» не было бы
    Schema::create('pages', function ($table): void {
        $table->id();
    });
    $types = Modules::types();
    $modules = array_map(fn (Module $module): string => $module->label.' — '.$module->description, Modules::all());
    $question = 'Что будет на сайте? (пробел — отметить, Enter — дальше)';

    $this->artisan('fastleo:install', $this->base)
        ->expectsChoice('Какая установка?', 'custom', $types)
        ->expectsChoice($question, ['pages', 'contacts'], $modules)
        ->expectsOutputToContain('таблица pages уже есть')
        ->expectsChoice($question, ['contacts'], $modules)
        ->expectsConfirmation(siteQuestion(), 'yes')
        ->expectsConfirmation('Выполнить миграции сейчас?', 'no')
        ->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo/Models/Page.php')->not->toBeFile()
        ->and($this->sandbox.'/app/Fastleo/ContactsSettings.php')->toBeFile();
});

it('creates the public/storage link with --setup when it is missing', function (): void {
    config(['fastleo.files.disk' => 'public', 'filesystems.disks.public.driver' => 'local']);
    File::deleteDirectory($this->sandbox.'/public/storage');
    File::ensureDirectoryExists($this->sandbox.'/storage/app/public');

    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--no-modules' => true, '--setup' => true])->assertSuccessful();

    expect(is_link($this->sandbox.'/public/storage'))->toBeTrue();
});

it('creates the administrator by flags even when there is nothing to migrate', function (): void {
    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--no-modules' => true, '--admin-email' => 'own@example.com', '--admin-password' => 'secret-pass-1'])
        ->assertSuccessful();

    expect(User::query()->where('email', 'own@example.com')->value('fastleo_admin'))->toBeTruthy();
});

it('fails when the requested administrator cannot be created', function (): void {
    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--no-modules' => true, '--admin-email' => 'own@example.com', '--admin-password' => 'abc'])
        ->assertFailed();

    expect(User::query()->where('email', 'own@example.com')->exists())->toBeFalse();
});

it('fails when a migration breaks, and does not go on to the administrator', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/database/migrations');
    File::put($this->sandbox.'/database/migrations/2020_01_01_000000_broken.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        throw new RuntimeException('сломано');\n    }\n};\n");

    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--modules' => 'contacts', '--setup' => true, '--admin-email' => 'own@example.com', '--admin-password' => 'secret-pass-1'])
        ->expectsOutputToContain('Миграции не выполнены: сломано')
        ->assertFailed();

    expect(User::query()->where('email', 'own@example.com')->exists())->toBeFalse();
});

it('fails when fastleo:doctor finds an error', function (): void {
    config(['auth.guards.fastleo' => ['driver' => 'token', 'provider' => 'users']]);

    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--no-modules' => true])->assertFailed();
});

it('reminds to run migrate once, in the final steps, after adding modules with tables', function (): void {
    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--modules' => 'news'])
        ->doesntExpectOutputToContain('выполните php artisan migrate')
        ->expectsOutputToContain('php artisan migrate —')
        ->assertSuccessful();
});

it('says so when the requested administrator already exists', function (): void {
    User::factory()->create(['email' => 'old@example.com']);

    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--no-modules' => true, '--admin-email' => 'old@example.com', '--admin-password' => 'secret-pass-1'])
        ->expectsOutputToContain('Пользователь old@example.com уже есть')
        ->assertSuccessful();

    expect(User::query()->where('email', 'old@example.com')->value('fastleo_admin'))->toBeTruthy();
});

it('fails when the administrator was asked for but the migrations have not run', function (): void {
    DB::table('migrations')->where('migration', '2026_10_28_000000_add_fastleo_admin_to_users_table')->delete();

    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--no-modules' => true, '--admin-email' => 'new@example.com', '--admin-password' => 'secret-pass-1'])
        ->expectsOutputToContain('Пользователь new@example.com не создан: таблиц ещё нет')
        ->assertFailed();

    expect(User::query()->where('email', 'new@example.com')->exists())->toBeFalse();
});

it('says that the examples are skipped on a production site', function (): void {
    $this->app['env'] = 'production';

    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--modules' => 'news', '--setup' => true, '--seed' => true])
        ->expectsOutputToContain('в боевом окружении (production) не добавляются')
        ->assertSuccessful();

    expect(DB::table('news')->count())->toBe(0);
});

it('fails and says which examples were not added when a seeder cannot run', function (): void {
    Artisan::command('db:seed {--class=}', function (): void {
        throw new RuntimeException('нет сидера');
    });

    $this->artisan('fastleo:install', [...$this->base, '--no-interaction' => true, '--modules' => 'news', '--setup' => true, '--seed' => true])
        ->expectsOutputToContain('Примеры не добавлены (Database\Seeders\NewsSeeder): нет сидера')
        ->assertFailed();
});

it('does not ask again when every chosen module is taken', function (): void {
    Schema::create('pages', function ($table): void {
        $table->id();
    });
    $types = Modules::types();
    $modules = array_map(fn (Module $module): string => $module->label.' — '.$module->description, Modules::all());

    $this->artisan('fastleo:install', $this->base)
        ->expectsChoice('Какая установка?', 'custom', $types)
        ->expectsChoice('Что будет на сайте? (пробел — отметить, Enter — дальше)', ['pages'], $modules)
        ->expectsOutputToContain('таблица pages уже есть')
        ->expectsConfirmation('Создать первого пользователя сейчас?', 'no')
        ->assertSuccessful();
});

it('does not ask about the administrator when the site already has one', function (): void {
    User::factory()->create()->forceFill(['fastleo_admin' => true])->save();

    $this->artisan('fastleo:install', $this->base)
        ->expectsChoice('Какая установка?', 'basic', Modules::types())
        ->assertSuccessful();
});

it('still asks about the administrator when the users table has no fastleo_admin column', function (): void {
    Schema::table('users', function ($table): void {
        $table->dropIndex(['fastleo_admin']);
        $table->dropColumn('fastleo_admin');
    });

    $this->artisan('fastleo:install', $this->base)
        ->expectsChoice('Какая установка?', 'basic', Modules::types())
        ->expectsConfirmation('Создать первого пользователя сейчас?', 'no')
        ->assertFailed(); // doctor ругается на отсутствие колонки, вопрос об пользователе при этом задан
});
