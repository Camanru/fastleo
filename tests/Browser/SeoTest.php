<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('counts the characters, previews the snippet and saves the values', function (): void {
    $article = Article::factory()->create(['title' => 'Статья про SEO']);

    $page = signInAndVisit(route('fastleo.resources.edit', ['articles', $article->id]));

    $page->click('[role="tablist"].overflow-x-auto [role="tab"]:nth-child(6)')
        ->assertSeeIn('[data-seo-title]', 'Заголовок страницы')
        ->type('#field-seo-title', 'Лечение зубов без боли')
        ->type('#field-seo-description', 'Современная анестезия')
        ->assertSeeIn('[data-seo-title]', 'Лечение зубов без боли')
        ->assertSeeIn('[data-seo-description]', 'Современная анестезия')
        ->assertSeeIn('[data-seo-title-count]', '22 / 60')
        ->assertSeeIn('[data-seo-description-count]', '21 / 160')
        ->keys('#field-seo-title', 'Control+s')
        ->assertSee('Сохранено.')
        ->assertNoJavaScriptErrors();

    expect($article->refresh()->seo)->toBe(['title' => 'Лечение зубов без боли', 'description' => 'Современная анестезия']);
});
