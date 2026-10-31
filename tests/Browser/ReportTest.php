<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
    useFixtureResources('Reports');
    Article::factory()->create(['status' => 'draft', 'views' => 10, 'created_at' => now()->subDay()]);
    Article::factory()->create(['status' => 'published', 'views' => 5, 'created_at' => now()]);
});

it('draws the chart with two lines and the table with the totals', function (): void {
    $page = signInAndVisit(route('fastleo.pages.show', ['page' => 'articles', 'filter' => ['period' => ['from' => now()->subDays(3)->toDateString(), 'to' => now()->toDateString()]]]));

    $page->assertPresent('canvas[data-chart][data-ready]')
        ->assertScript("document.querySelector('canvas[data-chart]').getBoundingClientRect().width > 100")
        ->assertSee('Итого')
        ->assertNoJavaScriptErrors();
});

it('applies a preset period and a chosen status without leaving the report', function (): void {
    $page = signInAndVisit(route('fastleo.pages.show', 'articles'));

    $page->click('[data-report-presets] a:nth-child(2)')
        ->assertAttribute('[data-report-presets] a[aria-current]', 'aria-current', 'true')
        ->assertSeeIn('[data-report-presets] a[aria-current]', 'Вчера')
        ->select('select[name="filter[status]"]', 'published')
        ->assertValue('select[name="filter[status]"]', 'published')
        ->assertSeeIn('[data-report-presets] a[aria-current]', 'Вчера')
        ->assertNoJavaScriptErrors();
});
