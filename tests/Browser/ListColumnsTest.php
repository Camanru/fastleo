<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('hides a column chosen in the list, remembers it after a reload and shows it again', function (): void {
    Article::factory()->create(['title' => 'Отчёт за март', 'phone' => '+7 900 111-22-33']);

    $page = signInAndVisit(route('fastleo.resources.index', 'articles'));

    $page->assertPresent('th[data-column="status"]')
        ->click('[data-columns-button]')
        ->click('[data-column-toggle="status"]')
        ->assertMissing('th[data-column="status"]:not([hidden])')
        ->assertNoJavaScriptErrors();

    $page->refresh()
        ->assertMissing('th[data-column="status"]:not([hidden])')
        ->assertSee('Отчёт за март')
        ->click('[data-columns-button]')
        ->click('[data-column-toggle="status"]')
        ->assertPresent('th[data-column="status"]:not([hidden])')
        ->assertNoJavaScriptErrors();
});

it('keeps a column hidden after the list refreshes itself', function (): void {
    useFixtureResources('Poll');
    Article::factory()->create(['title' => 'Первая статья']);

    $page = signInAndVisit(route('fastleo.resources.index', 'poll-articles'));

    $page->click('[data-columns-button]')
        ->click('[data-column-toggle="views"]')
        ->assertMissing('th[data-column="views"]:not([hidden])');

    Article::factory()->create(['title' => 'Вторая статья']);

    $page->wait(7)
        ->assertSee('Вторая статья')
        ->assertMissing('th[data-column="views"]:not([hidden])')
        ->assertNoJavaScriptErrors();
});

it('works when the saved choice is not a list of columns', function (): void {
    Article::factory()->create(['title' => 'Отчёт за март']);

    $page = signInAndVisit(route('fastleo.resources.index', 'articles'));

    $page->script("localStorage.setItem('fastleo.columns.articles', '{broken')");

    $page->refresh()
        ->assertPresent('th[data-column="status"]:not([hidden])')
        ->click('[data-columns-button]')
        ->click('[data-column-toggle="status"]')
        ->assertMissing('th[data-column="status"]:not([hidden])')
        ->assertNoJavaScriptErrors();
});

it('keeps the columns menu inside a phone screen', function (): void {
    Article::factory()->create(['title' => 'Отчёт за март']);

    $page = signInAndVisit(route('fastleo.resources.index', 'articles'))->resize(390, 844);

    $page->click('[data-columns-button]')
        ->assertScript("(() => { const menu = document.querySelector('[data-column-toggle=\"status\"]').closest('.fixed'); return menu.style.right !== '' && menu.getBoundingClientRect().left >= 0; })()", true)
        ->assertNoJavaScriptErrors();
});

it('never hides the first column, even if an old choice names it', function (): void {
    Article::factory()->create(['title' => 'Отчёт за март']);

    $page = signInAndVisit(route('fastleo.resources.index', 'articles'));

    $page->script("localStorage.setItem('fastleo.columns.articles', '[\"title\"]')");

    $page->refresh()
        ->assertPresent('th[data-column="title"]:not([hidden])')
        ->assertNoJavaScriptErrors();
});
