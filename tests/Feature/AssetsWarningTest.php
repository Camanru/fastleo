<?php

declare(strict_types=1);

use Fastleo\Support\Assets;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    // Публичная папка — во временной: общий каркас Testbench читают параллельные процессы тестов.
    $this->public = sys_get_temp_dir().'/fastleo-assets-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->public);
    $this->app->usePublicPath($this->public);
});

afterEach(function (): void {
    File::deleteDirectory($this->public);
});

it('tells the developer on every page that the assets are not published when debugging', function (): void {
    config(['app.debug' => true]);

    $this->get(route('fastleo.login'))
        ->assertOk()
        ->assertSee('Ассеты админки не опубликованы')
        ->assertSee('php artisan vendor:publish --tag=fastleo-assets --force');
});

it('stays silent in production even without published assets', function (): void {
    config(['app.debug' => false]);

    $this->get(route('fastleo.login'))->assertOk()->assertDontSee('Ассеты админки не опубликованы');
});

it('stays silent when the assets are published', function (): void {
    config(['app.debug' => true]);
    File::copyDirectory(Assets::distPath(), $this->public.'/vendor/fastleo');

    $this->get(route('fastleo.login'))->assertOk()->assertDontSee('Ассеты админки не опубликованы');
});
