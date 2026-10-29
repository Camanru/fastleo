<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Category;
use Workbench\App\Models\Topic;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('collapses a branch and remembers it after reload', function (): void {
    $root = Category::factory()->create(['title' => 'Каталог', 'sort' => 1]);
    Category::factory()->create(['title' => 'Вентиляция', 'parent_id' => $root->id, 'sort' => 1]);

    $page = signInAndVisit(route('fastleo.resources.index', 'categories'));

    $hidden = "getComputedStyle(document.querySelector('tbody tr:nth-child(2)')).display === 'none'";

    $page->assertScript($hidden, false)
        ->click('table [data-tree-toggle]')
        ->assertScript($hidden, true)
        ->refresh()
        ->assertScript($hidden, true)
        ->press('Развернуть всё')
        ->assertScript($hidden, false)
        ->assertNoJavaScriptErrors();
});

it('reorders records by dragging and with the arrows', function (): void {
    foreach (['А', 'Б', 'В'] as $index => $title) {
        Topic::factory()->create(['title' => $title, 'sort' => $index + 1]);
    }

    $page = signInAndVisit(route('fastleo.resources.index', 'topics'));

    $page->drag('tbody tr:nth-child(3) [data-reorder-handle]', 'tbody tr:nth-child(1)')
        ->assertNoJavaScriptErrors();

    expect(Topic::query()->orderBy('sort')->pluck('title')->all())->toBe(['В', 'А', 'Б']);

    $page->refresh()
        ->click('tbody tr:nth-child(3) [data-reorder="up"]')
        ->assertSeeIn('tbody tr:nth-child(2)', 'Б')
        ->assertNoJavaScriptErrors();

    expect(Topic::query()->orderBy('sort')->pluck('title')->all())->toBe(['В', 'Б', 'А']);
});
