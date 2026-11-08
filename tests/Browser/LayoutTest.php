<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Topic;

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
    Topic::factory()->create();

    $page = signInAndVisit(route('fastleo.resources.index', 'topics'));

    $page->assertScript("document.documentElement.classList.contains('fl-compact')", false)
        ->click('[title="Плотность таблицы"]')
        ->assertScript("document.documentElement.classList.contains('fl-compact')")
        ->assertScript("document.querySelector('tbody td').getBoundingClientRect().height < 48")
        ->refresh()
        ->assertScript("document.documentElement.classList.contains('fl-compact')")
        ->assertNoJavaScriptErrors();
});

it('collapses a menu group, remembers it and opens it on a page of its own section', function (): void {
    $files = "document.querySelector('[data-menu-group=\"Медиа\"] a[href$=\"/files\"]')";
    $visible = "{$files}.getBoundingClientRect().height > 0";

    $page = signInAndVisit(route('fastleo.dashboard'));

    $page->assertScript($visible)
        ->click('[data-menu-group="Медиа"] button')
        ->assertScript($visible, false)
        ->assertScript("document.querySelector('[data-menu-group=\"Медиа\"] button').getAttribute('aria-expanded')", 'false')
        ->refresh()
        ->assertScript($visible, false)
        ->press('Свернуть меню')
        ->assertScript($visible)
        ->click('[aria-label="Развернуть меню"]')
        ->assertScript($visible, false)
        ->navigate(route('fastleo.files.index'))
        ->assertScript($visible)
        ->assertNoJavaScriptErrors();
});

it('scrolls a long collapsed menu and shows the label of an item next to it', function (): void {
    $nav = "document.querySelector('#fastleo-menu nav')";
    $link = "document.querySelector('#fastleo-menu nav a[href$=\"/files\"]')";
    $tip = "{$link}.querySelector('[data-menu-tip]')";

    $page = signInAndVisit(route('fastleo.dashboard'))->resize(1280, 600);

    $page->press('Свернуть меню')
        ->assertScript("{$nav}.scrollHeight > {$nav}.clientHeight")
        ->click('[aria-label="Развернуть меню"]')
        ->assertScript("document.documentElement.classList.contains('fl-collapsed')", false)
        ->press('Свернуть меню')
        ->assertScript("document.getElementById('fastleo-menu').getBoundingClientRect().width === 64")
        ->hover('#fastleo-menu nav a[href$="/files"]')
        ->assertScript("Math.abs({$tip}.getBoundingClientRect().left - {$link}.getBoundingClientRect().right - 10) < 2")
        ->assertScript("getComputedStyle({$tip}).opacity === '1'")
        ->assertNoJavaScriptErrors();
});
