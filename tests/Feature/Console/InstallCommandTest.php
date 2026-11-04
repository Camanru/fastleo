<?php

declare(strict_types=1);

use Fastleo\Auth\FastleoUser;
use Fastleo\Auth\HasFastleoAccess;
use Fastleo\FastleoServiceProvider;
use Fastleo\Installer\ChildArtisan;
use Fastleo\Support\Assets;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Foundation\Auth\User as PlainUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;

beforeEach(function (): void {
    // Публикуем во временную папку: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-install-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/config');
    File::ensureDirectoryExists($this->sandbox.'/public');
    $this->app->useConfigPath($this->sandbox.'/config');
    $this->app->usePublicPath($this->sandbox.'/public');
    new FastleoServiceProvider($this->app)->boot();
});

afterEach(function (): void {
    File::deleteDirectory($this->sandbox);
});

it('publishes the config and assets and lists the next steps', function (): void {
    DB::table('migrations')->where('migration', '2026_10_28_000000_add_fastleo_admin_to_users_table')->delete();

    $exitCode = Artisan::call('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())
        ->toContain('php artisan migrate')
        ->toContain('php artisan fastleo:user')
        ->toContain(route('fastleo.login'));
    expect(config_path('fastleo.php'))->toBeFile()
        ->and(public_path(Assets::PUBLIC_PATH.'/fastleo.css'))->toBeFile()
        ->and(public_path(Assets::PUBLIC_PATH.'/fastleo.js'))->toBeFile();
});

it('does not suggest migrate once the migrations of the package have run', function (): void {
    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true])
        ->doesntExpectOutputToContain('php artisan migrate')
        ->expectsOutputToContain('php artisan fastleo:user')
        ->assertSuccessful();
});

it('suggests migrate when the database cannot be read', function (): void {
    $this->app->instance('migration.repository', Mockery::mock(MigrationRepositoryInterface::class, function (MockInterface $repository): void {
        $repository->shouldReceive('repositoryExists')->andThrow(new RuntimeException('Нет соединения с базой.'));
    }));

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true])
        ->expectsOutputToContain('php artisan migrate');
});

it('reminds about storage:link only while public/storage is missing', function (): void {
    config(['fastleo.files.disk' => 'public', 'filesystems.disks.public.driver' => 'local']);

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true])
        ->expectsOutputToContain('php artisan storage:link')
        ->assertSuccessful();

    File::ensureDirectoryExists(public_path('storage'));

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true])
        ->doesntExpectOutputToContain('php artisan storage:link')
        ->assertSuccessful();
});

it('puts the Claude skill next to the site and refreshes it on a repeat run', function (): void {
    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true])->assertSuccessful();

    expect($this->sandbox.'/skill/SKILL.md')->toBeFile();
    expect(File::get($this->sandbox.'/skill/SKILL.md'))->toContain('name: fastleo');

    File::put($this->sandbox.'/skill/SKILL.md', 'stale');
    $this->artisan('fastleo:skill', ['--path' => $this->sandbox.'/skill'])
        ->expectsOutputToContain('Skill для Claude Code обновлён')
        ->assertSuccessful();

    expect(File::get($this->sandbox.'/skill/SKILL.md'))->toContain('name: fastleo');
});

it('counts a relative path of the skill from the root of the site', function (): void {
    $this->app->setBasePath($this->sandbox);

    $this->artisan('fastleo:skill', ['--path' => 'tools/skill'])->assertSuccessful();

    expect($this->sandbox.'/tools/skill/SKILL.md')->toBeFile();
});

it('does not ask to create a user when an administrator already exists', function (): void {
    admin();

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true])
        ->doesntExpectOutputToContain('php artisan fastleo:user')
        ->assertSuccessful();
});

it('does not overwrite a config the site already has', function (): void {
    File::put(config_path('fastleo.php'), "<?php\n\nreturn ['path' => 'admin'];\n");

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true])->assertSuccessful();

    expect(File::get(config_path('fastleo.php')))->toContain("'path' => 'admin'");
});

it('warns when the queue runs synchronously', function (): void {
    config(['queue.default' => 'sync']);

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true])
        ->expectsOutputToContain('Очередь работает в режиме sync')
        ->assertSuccessful();
});

it('does not warn when a queue worker is used', function (): void {
    config(['queue.default' => 'database']);

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true])
        ->doesntExpectOutputToContain('Очередь работает в режиме sync')
        ->assertSuccessful();
});

/**
 * Модель пользователя сайта в папке app песочницы, как в новом Laravel, ещё без fastleo.
 *
 * @return array{class: class-string, file: string}
 */
function sandboxUserModel(string $sandbox): array
{
    $class = 'InstallUser'.uniqid();
    $file = $sandbox.'/app/Models/'.$class.'.php';
    File::ensureDirectoryExists(dirname($file));
    File::put($file, <<<PHP
        <?php

        namespace App\\Models;

        use Illuminate\\Foundation\\Auth\\User as Authenticatable;
        use Illuminate\\Notifications\\Notifiable;

        class {$class} extends Authenticatable
        {
            use Notifiable;

            protected \$table = 'users';
        }

        PHP);
    require $file;
    app()->useAppPath($sandbox.'/app');
    config(['auth.providers.users.model' => 'App\\Models\\'.$class]);

    return ['class' => 'App\\Models\\'.$class, 'file' => $file];
}

it('connects a plain user model of the site to the admin panel', function (): void {
    $model = sandboxUserModel($this->sandbox);
    fakeChildArtisan();

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true])
        ->expectsOutputToContain('Модель '.$model['class'].' подключена к админке')
        ->assertSuccessful();

    expect(File::get($model['file']))
        ->toContain('use '.FastleoUser::class.';')
        ->toContain('use '.HasFastleoAccess::class.';')
        ->toContain('extends Authenticatable implements FastleoUser')
        ->toContain('use HasFastleoAccess, Notifiable;');
});

/**
 * Подмена дочернего процесса artisan: запоминает команды и отвечает заданным кодом.
 */
function fakeChildArtisan(int $exitCode = 0): object
{
    $fake = new class extends ChildArtisan
    {
        /** @var list<array{string, array<string, string|bool|null>, bool}> */
        public array $calls = [];

        public int $exitCode = 0;

        public bool $canAsk = true;

        #[Override]
        public function canAsk(): bool
        {
            return $this->canAsk;
        }

        #[Override]
        public function run(string $command, array $arguments = [], bool $interactive = false, ?callable $output = null): int
        {
            $this->calls[] = [$command, $arguments, $interactive];

            return $this->exitCode;
        }
    };
    $fake->exitCode = $exitCode;
    app()->instance(ChildArtisan::class, $fake);

    return $fake;
}

it('creates the administrator and runs the check in a separate process in the run that has just connected the user model', function (): void {
    sandboxUserModel($this->sandbox);
    $child = fakeChildArtisan();

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true, '--no-modules' => true, '--admin-email' => 'own@example.com', '--admin-password' => 'secret-pass-1'])
        ->assertSuccessful();

    expect($child->calls)->toBe([
        ['fastleo:user', ['email' => 'own@example.com', '--name' => 'Администратор', '--password' => 'secret-pass-1'], false],
        ['fastleo:doctor', [], false],
    ]);
});

it('does not start a separate process when the user model was connected earlier', function (): void {
    $child = fakeChildArtisan();

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true])->assertSuccessful();

    expect($child->calls)->toBe([]);
});

it('fails when the separate process could not create the administrator', function (): void {
    sandboxUserModel($this->sandbox);
    fakeChildArtisan(1);

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true, '--no-modules' => true, '--admin-email' => 'own@example.com', '--admin-password' => 'secret-pass-1'])
        ->assertFailed();
});

it('does not touch a user model file it cannot write and asks to connect it by hand', function (): void {
    $model = sandboxUserModel($this->sandbox);
    $original = File::get($model['file']);
    chmod($model['file'], 0o444);

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true])
        ->expectsOutputToContain('не подключена к админке')
        ->assertFailed();

    chmod($model['file'], 0o644);
    expect(File::get($model['file']))->toBe($original);
});

it('does not connect a user model whose own file does not compile', function (): void {
    $model = sandboxUserModel($this->sandbox);
    $broken = str_replace("use Illuminate\\Notifications\\Notifiable;\n", "use Illuminate\\Notifications\\Notifiable;\nuse Illuminate\\Notifications\\Notifiable;\n", File::get($model['file']));
    expect($broken)->not->toBe(File::get($model['file']));

    // Класс уже загружен, поэтому в песочнице подменяется только содержимое файла.
    File::put($model['file'], $broken);

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true])
        ->expectsOutputToContain('не подключена к админке')
        ->assertFailed();

    expect(File::get($model['file']))->toBe($broken);
});

it('does not touch the user model when the site app folder is missing', function (): void {
    $model = sandboxUserModel($this->sandbox);
    $original = File::get($model['file']);
    app()->useAppPath($this->sandbox.'/missing');

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true])->assertFailed();

    expect(File::get($model['file']))->toBe($original);
});

it('does not touch a user model outside the site app folder', function (string $model): void {
    config(['auth.providers.users.model' => $model]);

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-interaction' => true])
        ->expectsOutputToContain(FastleoUser::class)
        ->assertFailed();
})->with([
    'Laravel base user' => [PlainUser::class],
    'not an Eloquent model' => [stdClass::class],
]);

it('does not start questions of the administrator in a separate process when the terminal cannot take them', function (): void {
    sandboxUserModel($this->sandbox);
    $child = fakeChildArtisan();
    $child->canAsk = false;

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill', '--no-modules' => true, '--setup' => true])
        ->expectsOutputToContain('php artisan fastleo:user')
        ->assertSuccessful();

    expect(array_column($child->calls, 0))->not->toContain('fastleo:user');
});
