<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;
use Workbench\App\Models\Feedback;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('draws the charts and shows widgets of the demo dashboard', function (): void {
    Article::factory()->count(3)->create();
    Feedback::factory()->create(['name' => 'Клиент из таблицы']);

    $page = signInAndVisit(route('fastleo.dashboard'));

    $page->assertSee('Статей на сайте')
        ->assertSee('Клиент из таблицы')
        ->assertPresent('canvas[data-chart][data-ready]')
        ->assertScript("document.querySelector('canvas[data-chart]').getBoundingClientRect().width > 100")
        ->assertSee('Состояние сервера')
        ->assertNoJavaScriptErrors();
});

it('asks before clearing the cache and cancels without clearing', function (): void {
    $page = signInAndVisit(route('fastleo.dashboard'));

    $page->click('[data-clear-cache]')
        ->assertScript("document.getElementById('fastleo-confirm').open")
        ->assertSeeIn('#fastleo-confirm', 'Очистить кеш сайта')
        ->click('#fastleo-confirm button[value=cancel]')
        ->assertScript("document.getElementById('fastleo-confirm').open", false)
        ->assertNoJavaScriptErrors();
});
