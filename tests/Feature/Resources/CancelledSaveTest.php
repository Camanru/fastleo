<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Workbench\App\Models\Article;
use Workbench\App\Models\ArticleLink;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('shows an error and keeps the input when an observer cancels creating', function (): void {
    Article::creating(fn (): bool => false);

    $this->post(route('fastleo.resources.store', 'articles'), articleData(['title' => 'Новая']))
        ->assertRedirect(route('fastleo.resources.create', 'articles'))
        ->assertSessionHas('fastleo.error', 'Сайт отменил сохранение (обработчик модели вернул false). Изменения не записаны.')
        ->assertSessionHasInput('title', 'Новая');

    expect(Article::query()->count())->toBe(0);
});

it('rolls back blocks and links when an observer cancels updating', function (): void {
    $article = Article::factory()->create(['title' => 'Было']);
    Article::updating(fn (): bool => false);

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['title' => 'Стало', 'links' => [['title' => 'Новая']]]))
        ->assertSessionHas('fastleo.error')
        ->assertSessionMissing('fastleo.success');

    expect($article->fresh()?->title)->toBe('Было')->and($article->links()->count())->toBe(0);
});

it('rolls back the record when an observer cancels saving a block', function (): void {
    $article = Article::factory()->create(['title' => 'Было']);
    ArticleLink::creating(fn (): bool => false);

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['title' => 'Стало', 'links' => [['title' => 'Новая']]]))
        ->assertSessionHas('fastleo.error');

    expect($article->fresh()?->title)->toBe('Было');
});

it('does not report a cancelled delete as done', function (): void {
    $article = Article::factory()->create();
    Article::deleting(fn (): bool => false);

    $this->delete(route('fastleo.resources.destroy', ['articles', $article->id]))
        ->assertSessionHas('fastleo.error', 'Сайт отменил удаление (обработчик модели вернул false). Запись не удалена.')
        ->assertSessionMissing('fastleo.success');

    expect($article->fresh()?->trashed())->toBeFalse();
});

it('does not report a cancelled permanent delete as done', function (): void {
    $article = Article::factory()->create();
    $article->delete();
    Article::forceDeleting(fn (): bool => false);

    $this->delete(route('fastleo.resources.force-delete', ['articles', $article->id]))
        ->assertSessionHas('fastleo.error')
        ->assertSessionMissing('fastleo.success');

    expect(Article::withTrashed()->find($article->id))->not->toBeNull();
});

it('does not put a password field with a custom name into the session after a validation error', function (): void {
    createSecretNotesTable();
    useFixtureResources('Secrets');

    $this->post(route('fastleo.resources.store', 'secret-notes'), ['title' => '', 'pin' => 'top-secret-pin'])
        ->assertSessionHasErrors('title')
        ->assertSessionMissing('_old_input.pin');
});
