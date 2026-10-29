<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Workbench\App\Models\Article;

it('does not run an action by a GET request', function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
    $article = Article::factory()->create();

    $this->get(route('fastleo.resources.index', 'articles').'/actions/delete?ids[]='.$article->id)->assertStatus(405);

    expect($article->fresh()?->trashed())->toBeFalse();
});

it('rejects an action without a csrf token with 419', function (): void {
    $this->app['env'] = 'production';
    $this->actingAs(admin(), Fastleo::GUARD);
    $article = Article::factory()->create();

    $this->post(route('fastleo.resources.action', ['articles', 'delete']), ['ids' => [(string) $article->id]])->assertStatus(419);

    expect($article->fresh()?->trashed())->toBeFalse();
});

it('sends a guest to the login page instead of running an action', function (): void {
    $article = Article::factory()->create();

    $this->post(route('fastleo.resources.action', ['articles', 'delete']), ['ids' => [(string) $article->id]])
        ->assertRedirect(route('fastleo.login'));

    expect($article->fresh()?->trashed())->toBeFalse();
});
