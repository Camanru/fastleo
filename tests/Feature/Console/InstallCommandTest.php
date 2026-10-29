<?php

declare(strict_types=1);

use Fastleo\Auth\FastleoUser;
use Fastleo\FastleoServiceProvider;
use Fastleo\Support\Assets;
use Illuminate\Foundation\Auth\User as PlainUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

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

it('fails when the user model does not implement FastleoUser', function (): void {
    config(['auth.providers.users.model' => PlainUser::class]);

    $this->artisan('fastleo:install', ['--skill-path' => $this->sandbox.'/skill'])
        ->expectsOutputToContain(FastleoUser::class)
        ->assertFailed();
});
