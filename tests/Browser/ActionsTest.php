<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('runs a bulk action on the selected rows after confirmation', function (): void {
    $first = Article::factory()->create(['title' => 'Первая']);
    $second = Article::factory()->create(['title' => 'Вторая']);
    $third = Article::factory()->create(['title' => 'Третья']);

    $page = signInAndVisit(route('fastleo.resources.index', 'articles'));

    $page->click("table input[value=\"{$first->id}\"]")
        ->click("table input[value=\"{$second->id}\"]")
        ->assertSeeIn('[data-bulk-bar]', 'Выбрано: 2')
        ->click('[data-bulk-bar] button:has-text("Опубликовать")')
        ->assertSeeIn('#fastleo-confirm', 'Опубликовать статьи на сайте?')
        ->click('#fastleo-confirm [data-confirm-primary]')
        ->assertSee('Опубликовано: 2')
        ->assertNoJavaScriptErrors();

    expect($first->fresh()?->is_published)->toBeTrue()
        ->and($second->fresh()?->is_published)->toBeTrue()
        ->and($third->fresh()?->is_published)->toBeFalse();
});

it('opens the mini form of an action from the row menu', function (): void {
    $article = Article::factory()->create(['title' => 'Статья', 'published_on' => '2026-01-01']);

    $page = signInAndVisit(route('fastleo.resources.index', 'articles'));

    $page->click('table [aria-haspopup=menu]')
        ->click('table [role=menuitem]:has-text("Назначить дату")')
        ->assertVisible('#fastleo-action-schedule-publication')
        ->fill('#field-action-schedule-publication-published_on', '2026-03-08')
        ->press('#fastleo-action-schedule-publication button[type=submit]')
        ->assertSee('Дата назначена: 1')
        ->assertNoJavaScriptErrors();

    expect($article->fresh()?->published_on?->toDateString())->toBe('2026-03-08');
});
