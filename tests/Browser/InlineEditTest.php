<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
    useFixtureResources('Inline');
    $this->article = Article::factory()->create(['title' => 'Статья', 'views' => 10, 'excerpt' => 'Анонс', 'status' => 'draft', 'type' => 'news', 'category_id' => null]);
});

it('saves a value when the cell loses focus and a list when another option is chosen', function (): void {
    $page = signInAndVisit(route('fastleo.resources.index', 'inline-articles'));
    $views = 'tbody [data-inline="views"] input';
    $status = 'tbody [data-inline="status"] select';

    $page->fill($views, '77')
        ->click('tbody [data-inline="excerpt"] input')
        ->waitForEvent('networkidle')
        ->assertValue($views, '77')
        ->select($status, 'published')
        ->waitForEvent('networkidle')
        ->assertUrlIs(route('fastleo.resources.index', 'inline-articles'))
        ->assertNoJavaScriptErrors();

    $this->article->refresh();

    expect($this->article->views)->toBe(77)
        ->and($this->article->status->value)->toBe('published');
});

it('shows the error and puts the old value back when the server refuses', function (): void {
    $page = signInAndVisit(route('fastleo.resources.index', 'inline-articles'));
    $views = 'tbody [data-inline="views"] input';

    $page->fill($views, '-5')
        ->click('tbody [data-inline="excerpt"] input')
        ->waitForText('0')
        ->assertValue($views, '10')
        ->assertSeeIn('tbody [data-inline="views"] [role=alert]', 'Просмотры')
        ->assertNoJavaScriptErrors();

    expect($this->article->refresh()->views)->toBe(10);
});

it('recounts the columns of the row that come from the query and paints the status list', function (): void {
    $page = signInAndVisit(route('fastleo.resources.index', 'inline-articles'));
    $views = 'tbody [data-inline="views"] input';

    $page->assertSeeIn('tbody td[data-field="views_double"]', '20')
        ->assertScript("document.querySelector('tbody [data-inline=\"status\"] select').className.includes('bg-sunken')", true)
        ->fill($views, '77')
        ->click('tbody [data-inline="excerpt"] input')
        ->waitForText('154')
        ->select('tbody [data-inline="status"] select', 'published')
        ->waitForEvent('networkidle')
        ->assertScript("document.querySelector('tbody [data-inline=\"status\"] select').className.includes('bg-success-soft')", true)
        ->assertNoJavaScriptErrors();
});
