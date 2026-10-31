<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
    useFixtureResources('Expand');
    $this->category = Category::factory()->create(['title' => 'Вентиляция']);
    $this->article = Article::factory()->create(['title' => 'Решётка', 'category_id' => $this->category->id]);
    $this->article->links()->create(['title' => 'Паспорт изделия', 'url' => 'https://example.com']);
});

it('opens the tables of a record under its row, hides them again and keeps the outer table', function (): void {
    $page = signInAndVisit(route('fastleo.resources.show', ['categories', $this->category->id]));
    $outer = '[data-related="articles"]';
    $arrow = $outer.' tbody tr:first-child button[data-expand]';

    $page->assertSeeIn($outer, 'Решётка')
        ->assertScript("document.querySelector('{$outer} [data-expansion]').hidden", true)
        ->click($arrow)
        ->waitForText('Паспорт изделия')
        ->assertScript("document.querySelector('{$outer} [data-expansion]').hidden", false)
        ->assertScript("document.querySelector('{$arrow}').getAttribute('aria-expanded')", 'true')
        ->assertSeeIn($outer.' [data-expansion] [data-related="links"]', 'Ссылки статьи')
        ->click($arrow)
        ->assertScript("document.querySelector('{$outer} [data-expansion]').hidden", true)
        ->assertSeeIn($outer, 'Решётка')
        ->assertNoJavaScriptErrors();
});

it('does not reload the nested tables when the row is opened a second time', function (): void {
    $page = signInAndVisit(route('fastleo.resources.show', ['categories', $this->category->id]));
    $arrow = '[data-related="articles"] tbody tr:first-child button[data-expand]';

    $page->click($arrow)->waitForText('Паспорт изделия')->click($arrow)->click($arrow)
        ->assertScript("document.querySelectorAll('[data-related=\"links\"]').length", 1)
        ->assertNoJavaScriptErrors();
});

it('opens a row inside an opened row without touching the outer table', function (): void {
    $child = Category::factory()->create(['title' => 'Решётки и диффузоры', 'parent_id' => $this->category->id]);
    $grandchild = Category::factory()->create(['title' => 'Диффузоры потолочные', 'parent_id' => $child->id]);

    $page = signInAndVisit(route('fastleo.resources.show', ['categories', $this->category->id]));
    $first = '[data-related="children"] tbody tr:first-child button[data-expand]';
    $second = '[data-related="children"] [data-expansion] [data-related="children"] tbody tr:first-child button[data-expand]';

    $page->assertSeeIn('[data-related="children"]', 'Решётки и диффузоры')
        ->click($first)
        ->waitForText('Подрубрики')
        ->assertSee($grandchild->title)
        ->click($second)
        ->assertScript("document.querySelectorAll('[data-related=\"children\"] [data-expansion]:not([hidden])').length", 2)
        ->assertScript("document.querySelector('{$first}').getAttribute('aria-expanded')", 'true')
        ->assertNoJavaScriptErrors();
});
