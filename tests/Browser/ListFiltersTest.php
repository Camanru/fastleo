<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('applies a filter as soon as an option is chosen and keeps the search', function (): void {
    $news = Category::factory()->create(['title' => 'Новости']);
    Article::factory()->published()->create(['title' => 'Отчёт за март', 'category_id' => $news->id]);
    Article::factory()->create(['title' => 'Отчёт за апрель']);
    Article::factory()->published()->create(['title' => 'Акция']);

    $page = signInAndVisit(route('fastleo.resources.index', ['resource' => 'articles', 'q' => 'Отчёт']));

    $page->press('Фильтры')
        ->select('select[name="filter[is_published]"]', '1')
        ->assertSee('Отчёт за март')
        ->assertDontSee('Отчёт за апрель')
        ->assertDontSee('Акция')
        ->assertSeeIn('[data-filters-count]', '1')
        ->click('#filter-category_id')
        ->click('[role=option]')
        ->assertSeeIn('[data-filters-count]', '2')
        ->assertSee('Отчёт за март')
        ->assertNoJavaScriptErrors();
});
