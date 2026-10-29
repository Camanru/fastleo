<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('opens with Ctrl+K, finds a record while typing and opens it with Enter', function (): void {
    $category = Category::factory()->create(['title' => 'Уникальная рубрика']);

    $page = signInAndVisit(route('fastleo.dashboard'));

    expect($page->script("document.querySelector('[data-search-input]').offsetParent === null"))->toBeTrue();

    $page->keys('[data-search-open]', 'Control+k')
        ->assertVisible('[data-search-input]')
        ->type('[data-search-input]', 'Уникальная')
        ->assertSeeIn('[data-search-results]', 'Уникальная рубрика')
        ->keys('[data-search-input]', 'Enter')
        ->assertPathIs('/fastleo/categories/'.$category->id.'/edit')
        ->assertNoJavaScriptErrors();
});

it('shows a hint for a short query, nothing found for a stranger and closes with Escape', function (): void {
    $page = signInAndVisit(route('fastleo.dashboard'));

    $page->click('[data-search-open]')
        ->assertSeeIn('[data-search-results]', 'не меньше двух символов')
        ->type('[data-search-input]', 'такого-нет-нигде')
        ->assertSeeIn('[data-search-results]', 'Ничего не найдено')
        ->keys('[data-search-input]', 'Escape')
        ->assertDontSee('Ничего не найдено')
        ->assertNoJavaScriptErrors();
});
