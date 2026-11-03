<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
    useFixtureResources('RelatedTab');
    $this->category = Category::factory()->create(['title' => 'Вентиляция']);
    Article::factory()->create(['title' => 'Приточная', 'category_id' => $this->category->id]);
});

it('switches between the fields and the table of a tab and hides the save bar over the table', function (): void {
    $page = signInAndVisit(route('fastleo.resources.edit', ['categories', $this->category->id]));
    $panel = '[data-tab-tables="stati"]';

    $page->assertScript("getComputedStyle(document.querySelector('{$panel}')).display", 'none')
        ->assertScript("getComputedStyle(document.querySelector('[data-save]').closest('div')).display !== 'none'", true)
        ->click('[role=tab]:has-text("Статьи")')
        ->assertSee('Приточная')
        ->assertScript("getComputedStyle(document.querySelector('{$panel}')).display !== 'none'", true)
        ->assertScript("getComputedStyle(document.querySelector('[data-save]').closest('div[class*=sticky]')).display", 'none')
        ->assertScript('location.hash', '#stati')
        ->click('[role=tab]:has-text("Основное")')
        ->assertScript("getComputedStyle(document.querySelector('[data-save]').closest('div[class*=sticky]')).display !== 'none'", true)
        ->assertNoJavaScriptErrors();
});

it('opens the tab named in the address', function (): void {
    $page = signInAndVisit(route('fastleo.resources.edit', ['categories', $this->category->id]).'#stati');

    $page->assertScript("getComputedStyle(document.querySelector('[data-tab-tables=\"stati\"]')).display !== 'none'", true)
        ->assertNoJavaScriptErrors();
});
