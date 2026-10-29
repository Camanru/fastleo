<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Mcp\FastleoServer;
use Fastleo\Mcp\Tools\RunAction;
use Fastleo\Publishing\Publication;
use Fastleo\Tests\Fixtures\Policies\NoCreatePublicationPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    $this->me = admin();
    $this->actingAs($this->me, Fastleo::GUARD);
    Http::preventStrayRequests();
    Storage::fake('public');
});

/**
 * Нажать «Опубликовать в каналы» у статьи.
 */
function publishArticle(Article $article): TestResponse
{
    return test()->post(route('fastleo.resources.action', ['articles', 'fastleo-publish']), ['ids' => [(string) $article->id]]);
}

it('shows the button only while there is an enabled channel', function (): void {
    $article = Article::factory()->create();

    $this->get(route('fastleo.resources.edit', ['articles', $article->id]))->assertDontSee('Опубликовать в каналы');

    publishingChannel();

    $this->get(route('fastleo.resources.edit', ['articles', $article->id]))->assertSee('Опубликовать в каналы');
});

it('creates a draft from the record with a copy of the image and opens it', function (): void {
    $channel = publishingChannel();
    publishingChannel(['name' => 'Выключенный', 'enabled' => false]);
    Storage::disk('public')->put('uploads/articles/cover.jpg', 'jpeg');
    $article = Article::factory()->create(['title' => 'Открытие', 'excerpt' => 'Ждём всех', 'cover' => 'uploads/articles/cover.jpg']);

    $response = publishArticle($article);

    $publication = Publication::query()->sole();
    $response->assertRedirect(route('fastleo.resources.edit', ['publications', $publication->id]))->assertSessionHas('fastleo.success');
    expect($publication)
        ->text->toBe("**Открытие**\n\nЖдём всех")
        ->button_label->toBe('Читать на сайте')
        ->button_url->toBe(route('articles.show', $article->id))
        ->channel_ids->toBe([(string) $channel->id])
        ->source_resource->toBe('articles')
        ->source_id->toBe((string) $article->id)
        ->status->toBe('draft')
        ->images->toBe(['uploads/publications/'.$publication->id.'/1.jpg']);
    Storage::disk('public')->assertExists('uploads/publications/'.$publication->id.'/1.jpg');
    Http::assertNothingSent();

    $this->get(route('fastleo.resources.show', ['publications', $publication->id]))->assertSee(route('fastleo.resources.show', ['articles', $article->id]), false);
});

it('skips images that are missing, not pictures or outside the disk', function (?string $cover): void {
    publishingChannel();
    Storage::disk('public')->put('uploads/articles/file.svg', '<svg/>');
    $article = Article::factory()->create(['cover' => $cover]);

    publishArticle($article);

    expect(Publication::query()->sole()->images)->toBe([]);
})->with([
    'no cover' => [null],
    'missing file' => ['uploads/articles/gone.jpg'],
    'svg' => ['uploads/articles/file.svg'],
    'path outside' => ['uploads/../../.env.jpg'],
]);

it('removes the copies with the publication but not the files picked from the file manager', function (): void {
    publishingChannel();
    Storage::disk('public')->put('uploads/articles/cover.jpg', 'jpeg');
    Storage::disk('public')->put('uploads/shared/logo.png', 'png');
    publishArticle(Article::factory()->create(['cover' => 'uploads/articles/cover.jpg']));
    $publication = Publication::query()->sole();
    $publication->update(['images' => [...$publication->images ?? [], 'uploads/shared/logo.png']]);

    $this->delete(route('fastleo.resources.destroy', ['publications', $publication->id]))->assertRedirect();

    Storage::disk('public')->assertMissing('uploads/publications/'.$publication->id.'/1.jpg');
    Storage::disk('public')->assertExists('uploads/shared/logo.png');
    Storage::disk('public')->assertExists('uploads/articles/cover.jpg');
});

it('is hidden and refused when the admin may not create publications', function (): void {
    publishingChannel();
    Gate::policy(Publication::class, NoCreatePublicationPolicy::class);
    $article = Article::factory()->create();

    $this->get(route('fastleo.resources.edit', ['articles', $article->id]))->assertDontSee('Опубликовать в каналы');
    publishArticle($article)->assertForbidden();

    expect(Publication::query()->count())->toBe(0);
});

it('gives an AI agent a draft through MCP', function (): void {
    publishingChannel();
    $article = Article::factory()->create();

    FastleoServer::actingAs($this->me, 'fastleo')->tool(RunAction::class, ['resource' => 'articles', 'action' => 'fastleo-publish', 'ids' => [(string) $article->id]])->assertHasNoErrors();

    expect(Publication::query()->sole()->status)->toBe('draft');
});
