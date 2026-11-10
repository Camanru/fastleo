<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\View;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
    useFixtureResources('Summary');
    View::addNamespace('summaryfix', __DIR__.'/../Fixtures/Summary/views');
    $this->category = Category::factory()->create(['title' => 'Вентиляция']);
    $this->article = Article::factory()->create(['title' => 'Старая статья', 'category_id' => $this->category->id]);
});

it('refreshes the card above the form after a related record is saved in a window', function (): void {
    $form = "document.querySelector('#fastleo-record-modal iframe').contentDocument";
    $page = signInAndVisit(route('fastleo.resources.edit', ['categories', $this->category->id]));

    $page->assertSeeIn('[data-record-summary]', 'Первая статья: Старая статья')
        ->click('[data-related-table="articles"] a[title="Изменить"]');

    waitUntil($page, "!!{$form}?.querySelector('[data-save]')");

    $page->script("(() => { const d = {$form}; const input = d.querySelector('input[name=title]'); input.value = 'Новая статья'; input.dispatchEvent(new Event('input', { bubbles: true })); d.querySelector('[data-save]').click(); })()");

    waitUntil($page, "document.querySelector('[data-record-summary]').textContent.includes('Новая статья')");

    $page->assertSeeIn('[data-record-summary]', 'Первая статья: Новая статья')
        ->assertScript("document.getElementById('fastleo-record-modal').open", false)
        ->assertNoJavaScriptErrors();

    expect($this->article->refresh()->title)->toBe('Новая статья');
});
