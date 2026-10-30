<?php

declare(strict_types=1);

use Fastleo\Tests\Fixtures\Policies\NoUpdateArticlePolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
    useFixtureResources('QuickToggle');
});

it('switches the value from the list without leaving the page', function (): void {
    $article = Article::factory()->create(['title' => 'Акция', 'is_published' => false]);

    $page = signInAndVisit(route('fastleo.resources.index', 'articles'));
    $selector = 'tbody tr button[aria-pressed]';

    $page->assertAttribute($selector, 'aria-pressed', 'false')
        ->click($selector)
        ->waitForEvent('networkidle')
        ->assertAttribute($selector, 'aria-pressed', 'true')
        ->assertUrlIs(route('fastleo.resources.index', 'articles'))
        ->assertNoJavaScriptErrors();

    expect($article->refresh()->is_published)->toBeTrue();

    $page->click($selector)
        ->waitForEvent('networkidle')
        ->assertAttribute($selector, 'aria-pressed', 'false');

    expect($article->refresh()->is_published)->toBeFalse();
});

it('keeps the old value and shows the message when the server refuses', function (): void {
    $article = Article::factory()->create(['title' => 'Акция', 'is_published' => false]);

    $page = signInAndVisit(route('fastleo.resources.index', 'articles'));

    // Право отозвали уже после показа страницы.
    Gate::policy(Article::class, NoUpdateArticlePolicy::class);

    $page->click('tbody tr button[aria-pressed]')
        ->waitForEvent('networkidle')
        ->assertAttribute('tbody tr button[aria-pressed]', 'aria-pressed', 'false')
        ->assertSeeIn('tbody tr [role=alert]', 'Не удалось изменить значение')
        ->assertNoJavaScriptErrors();

    expect($article->refresh()->is_published)->toBeFalse();
});
