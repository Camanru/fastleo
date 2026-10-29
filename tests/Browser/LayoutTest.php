<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('collapses the side menu to a narrow strip and remembers the choice', function (): void {
    $page = signInAndVisit(route('fastleo.dashboard'));

    $page->assertScript("document.documentElement.classList.contains('fl-collapsed')", false)
        ->assertScript("document.getElementById('fastleo-menu').getBoundingClientRect().width === 248")
        ->press('Свернуть меню')
        ->assertScript("document.documentElement.classList.contains('fl-collapsed')")
        ->assertScript("document.getElementById('fastleo-menu').getBoundingClientRect().width === 64")
        ->refresh()
        ->assertScript("document.documentElement.classList.contains('fl-collapsed')")
        ->assertNoJavaScriptErrors();
});

it('switches the table to compact rows and remembers the choice', function (): void {
    Article::factory()->create();

    $page = signInAndVisit(route('fastleo.resources.index', 'articles'));

    $page->assertScript("document.documentElement.classList.contains('fl-compact')", false)
        ->click('[title="Плотность таблицы"]')
        ->assertScript("document.documentElement.classList.contains('fl-compact')")
        ->assertScript("document.querySelector('tbody td').getBoundingClientRect().height < 48")
        ->refresh()
        ->assertScript("document.documentElement.classList.contains('fl-compact')")
        ->assertNoJavaScriptErrors();
});
