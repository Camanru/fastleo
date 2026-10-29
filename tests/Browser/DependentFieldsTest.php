<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('shows a field only while the checkbox is on', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'articles'));
    $shown = "getComputedStyle(document.querySelector('[data-visible-when=is_featured]')).display !== 'none'";

    $page->assertScript($shown, false)
        ->click('#field-is_featured')
        ->assertScript($shown, true)
        ->click('#field-is_featured')
        ->assertScript($shown, false)
        ->assertNoJavaScriptErrors();
});

it('reloads the subcategories when the category changes', function (): void {
    $catalog = Category::factory()->create(['title' => 'Каталог']);
    Category::factory()->create(['title' => 'Вентиляторы', 'parent_id' => $catalog->id]);
    $news = Category::factory()->create(['title' => 'Новости']);
    $events = Category::factory()->create(['title' => 'События', 'parent_id' => $news->id]);

    $page = signInAndVisit(route('fastleo.resources.create', 'articles'));

    $page->fill('title', 'Статья')
        ->select('status', 'draft')
        ->click('#field-category_id')
        ->type('#field-category_id', 'Новости')
        ->keys('#field-category_id', 'Enter')
        ->click('#field-subcategory_id')
        ->assertSeeIn('[data-combobox=subcategory_id] [role=listbox]', 'События')
        ->assertDontSeeIn('[data-combobox=subcategory_id] [role=listbox]', 'Вентиляторы')
        ->keys('#field-subcategory_id', 'Enter')
        ->press('Сохранить')
        ->assertSee('Сохранено.')
        ->assertNoJavaScriptErrors();

    expect(Article::query()->sole()->only('category_id', 'subcategory_id'))->toBe(['category_id' => $news->id, 'subcategory_id' => $events->id]);
});
