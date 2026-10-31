<?php

declare(strict_types=1);

use Fastleo\Auth\FastleoUser;
use Fastleo\Auth\HasFastleoAccess;
use Fastleo\FastleoServiceProvider;
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

    $exitCode = Artisan::call('fastleo:install', ['--skill-path' => $this->sandbox.'/skill']);

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
    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill'])
        ->doesntExpectOutputToContain('php artisan migrate')
        ->expectsOutputToContain('php artisan fastleo:user')
        ->assertSuccessful();
});

it('suggests migrate when the database cannot be read', function (): void {
    $this->app->instance('migration.repository', Mockery::mock(MigrationRepositoryInterface::class, function (MockInterface $repository): void {
        $repository->shouldReceive('repositoryExists')->andThrow(new RuntimeException('Нет соединения с базой.'));
    }));

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill'])
        ->expectsOutputToContain('php artisan migrate');
});

it('reminds about storage:link only while public/storage is missing', function (): void {
    config(['fastleo.files.disk' => 'public', 'filesystems.disks.public.driver' => 'local']);

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill'])
        ->expectsOutputToContain('php artisan storage:link')
        ->assertSuccessful();

    File::ensureDirectoryExists(public_path('storage'));

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill'])
        ->doesntExpectOutputToContain('php artisan storage:link')
        ->assertSuccessful();
});

it('puts the Claude skill next to the site and refreshes it on a repeat run', function (): void {
    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill'])->assertSuccessful();

    expect($this->sandbox.'/skill/SKILL.md')->toBeFile();
    expect(File::get($this->sandbox.'/skill/SKILL.md'))->toContain('name: fastleo');

    File::put($this->sandbox.'/skill/SKILL.md', 'stale');
    $this->artisan('fastleo:skill', ['--path' => $this->sandbox.'/skill'])
        ->expectsOutputToContain('Skill для Claude Code обновлён')
        ->assertSuccessful();

    expect(File::get($this->sandbox.'/skill/SKILL.md'))->toContain('name: fastleo');
});

it('does not overwrite a config the site already has', function (): void {
    File::put(config_path('fastleo.php'), "<?php\n\nreturn ['path' => 'admin'];\n");

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill'])->assertSuccessful();

    expect(File::get(config_path('fastleo.php')))->toContain("'path' => 'admin'");
});

it('warns when the queue runs synchronously', function (): void {
    config(['queue.default' => 'sync']);

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill'])
        ->expectsOutputToContain('Очередь работает в режиме sync')
        ->assertSuccessful();
});

it('does not warn when a queue worker is used', function (): void {
    config(['queue.default' => 'database']);

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill'])
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
        }

        PHP);
    require $file;
    app()->useAppPath($sandbox.'/app');
    config(['auth.providers.users.model' => 'App\\Models\\'.$class]);

    return ['class' => 'App\\Models\\'.$class, 'file' => $file];
}

it('connects a plain user model of the site to the admin panel', function (): void {
    $model = sandboxUserModel($this->sandbox);

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill'])
        ->expectsOutputToContain('Модель '.$model['class'].' подключена к админке')
        ->assertSuccessful();

    expect(File::get($model['file']))
        ->toContain('use '.FastleoUser::class.';')
        ->toContain('use '.HasFastleoAccess::class.';')
        ->toContain('extends Authenticatable implements FastleoUser')
        ->toContain('use HasFastleoAccess, Notifiable;');
});

it('does not touch a user model file it cannot write and asks to connect it by hand', function (): void {
    $model = sandboxUserModel($this->sandbox);
    $original = File::get($model['file']);
    chmod($model['file'], 0o444);

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill'])
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

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill'])
        ->expectsOutputToContain('не подключена к админке')
        ->assertFailed();

    expect(File::get($model['file']))->toBe($broken);
});

it('does not touch the user model when the site app folder is missing', function (): void {
    $model = sandboxUserModel($this->sandbox);
    $original = File::get($model['file']);
    app()->useAppPath($this->sandbox.'/missing');

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill'])->assertFailed();

    expect(File::get($model['file']))->toBe($original);
});

it('does not touch a user model outside the site app folder', function (string $model): void {
    config(['auth.providers.users.model' => $model]);

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill'])
        ->expectsOutputToContain(FastleoUser::class)
        ->assertFailed();
})->with([
    'Laravel base user' => [PlainUser::class],
    'not an Eloquent model' => [stdClass::class],
]);
