<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('picks a category from the list with the keyboard', function (): void {
    Category::factory()->create(['title' => 'Акции']);
    $news = Category::factory()->create(['title' => 'Новости']);

    $page = signInAndVisit(route('fastleo.resources.create', 'articles'));

    $page->fill('title', 'Статья')
        ->select('status', 'draft')
        ->click('#field-category_id')
        ->assertSeeIn('[data-combobox=category_id] [role=listbox]', 'Акции')
        ->type('#field-category_id', 'нов')
        ->assertDontSeeIn('[data-combobox=category_id] [role=listbox]', 'Акции')
        ->keys('#field-category_id', 'Enter')
        ->assertScript("document.querySelector('[data-combobox=category_id] input[name=category_id]').value", (string) $news->id)
        ->press('Сохранить')
        ->assertSee('Сохранено.')
        ->assertNoJavaScriptErrors();

    expect(Article::query()->sole()->category_id)->toBe($news->id);
});

it('searches categories on the server when there are many', function (): void {
    config(['fastleo.relations.preload' => 1]);
    Category::factory()->create(['title' => 'Акции']);
    $news = Category::factory()->create(['title' => 'Новости клиники']);
    $article = Article::factory()->create();

    $page = signInAndVisit(route('fastleo.resources.edit', ['articles', $article->id]));

    $page->click('#field-category_id')
        ->type('#field-category_id', 'клиник')
        ->assertSeeIn('[data-combobox=category_id] [role=listbox]', 'Новости клиники')
        ->assertDontSeeIn('[data-combobox=category_id] [role=listbox]', 'Акции')
        ->click('[data-combobox=category_id] [role=option]')
        ->press('Сохранить')
        ->assertSee('Сохранено.')
        ->assertNoJavaScriptErrors();

    expect($article->fresh()?->category_id)->toBe($news->id);
});

it('adds, removes and drags nested blocks', function (): void {
    $article = Article::factory()->create();
    $article->links()->create(['title' => 'Первая', 'sort' => 1]);
    $article->links()->create(['title' => 'Вторая', 'sort' => 2]);

    $page = signInAndVisit(route('fastleo.resources.edit', ['articles', $article->id]));

    $page->click('[role=tab]:has-text("Ссылки")')
        ->click('[data-block-add]')
        ->assertScript("document.querySelectorAll('[data-blocks=links] [data-block]').length", 3)
        ->fill('[data-blocks=links] [data-block]:nth-child(3) input[name$="[title]"]', 'Третья')
        ->click('[data-blocks=links] [data-block]:nth-child(1) [data-block-remove]')
        ->drag('[data-blocks=links] [data-block]:nth-child(2) [data-block-handle]', '[data-blocks=links] [data-block]:nth-child(1)')
        ->press('Сохранить')
        ->assertSee('Сохранено.')
        ->assertNoJavaScriptErrors();

    expect($article->links()->orderBy('sort')->pluck('title')->all())->toBe(['Третья', 'Вторая']);
});
