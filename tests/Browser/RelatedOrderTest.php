<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
    useFixtureResources('RelatedOrder');
    $this->article = Article::factory()->create(['title' => 'Приточная']);

    foreach (['Первая', 'Вторая', 'Третья'] as $index => $title) {
        DB::table('article_links')->insert(['article_id' => $this->article->id, 'title' => $title, 'sort' => $index + 1, 'created_at' => now(), 'updated_at' => now()]);
    }
});

it('reorders the records of a related table by dragging', function (): void {
    $titles = fn (): array => DB::table('article_links')->orderBy('sort')->pluck('title')->all();
    $rows = '[data-related-table="links"] tbody tr';

    $page = signInAndVisit(route('fastleo.resources.edit', ['articles', $this->article->id]));

    $page->assertSee('Третья')
        ->drag("{$rows}:nth-child(3) [data-reorder-handle]", "{$rows}:nth-child(1)")
        ->waitForEvent('networkidle')
        ->assertNoJavaScriptErrors();

    expect($titles())->toBe(['Третья', 'Первая', 'Вторая']);

    $page->refresh()
        ->waitForEvent('networkidle')
        ->assertSee('Третья')
        ->assertSeeIn("{$rows}:nth-child(1)", 'Третья');
});
