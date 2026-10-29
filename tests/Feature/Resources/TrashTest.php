<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Database\QueryException;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('moves a record to the trash', function (): void {
    $article = Article::factory()->create();

    $response = $this->delete(route('fastleo.resources.destroy', ['articles', $article->id]));

    $response->assertRedirect(route('fastleo.resources.index', 'articles'))
        ->assertSessionHas('fastleo.success', 'Запись перемещена в корзину.');
    $this->assertSoftDeleted($article);
});

it('lists only trashed records in the trash', function (): void {
    Article::factory()->create(['title' => 'Живая']);
    Article::factory()->create(['title' => 'Удалённая'])->delete();

    $this->get(route('fastleo.resources.index', 'articles'))->assertSee('Живая')->assertDontSee('Удалённая');
    $this->get(route('fastleo.resources.index', ['resource' => 'articles', 'trashed' => 1]))
        ->assertOk()
        ->assertSee('Удалённая')
        ->assertDontSee('Живая')
        ->assertSee(route('fastleo.resources.restore', ['articles', Article::withTrashed()->where('title', 'Удалённая')->sole()->id]))
        ->assertSee(route('fastleo.resources.show', ['articles', Article::withTrashed()->where('title', 'Удалённая')->sole()->id]));
});

it('restores a record from the trash', function (): void {
    $article = Article::factory()->create();
    $article->delete();

    $response = $this->post(route('fastleo.resources.restore', ['articles', $article->id]));

    $response->assertRedirect(route('fastleo.resources.index', ['resource' => 'articles', 'trashed' => 1]))
        ->assertSessionHas('fastleo.success', 'Запись восстановлена.');
    $this->assertNotSoftDeleted($article);
});

it('deletes a trashed record for good', function (): void {
    $article = Article::factory()->create();
    $article->delete();

    $this->delete(route('fastleo.resources.force-delete', ['articles', $article->id]))
        ->assertSessionHas('fastleo.success', 'Запись удалена.');

    expect(Article::withTrashed()->find($article->id))->toBeNull();
});

it('explains why the database refused to delete a trashed record for good', function (): void {
    $article = Article::factory()->create();
    $article->delete();
    Article::forceDeleting(function (): never {
        throw new QueryException('sqlite', 'delete from articles', [], new PDOException('FOREIGN KEY constraint failed'));
    });

    $this->delete(route('fastleo.resources.force-delete', ['articles', $article->id]))
        ->assertRedirect(route('fastleo.resources.index', 'articles'))
        ->assertSessionHas('fastleo.error');

    $this->assertSoftDeleted($article);
});

it('does not restore or force delete a record that is not in the trash', function (): void {
    $article = Article::factory()->create();

    $this->post(route('fastleo.resources.restore', ['articles', $article->id]))->assertNotFound();
    $this->delete(route('fastleo.resources.force-delete', ['articles', $article->id]))->assertNotFound();
    $this->assertNotSoftDeleted($article);
});

it('does not edit a trashed record but shows it', function (): void {
    $article = Article::factory()->create(['title' => 'В корзине']);
    $article->delete();

    $this->get(route('fastleo.resources.edit', ['articles', $article->id]))->assertNotFound();
    $this->put(route('fastleo.resources.update', ['articles', $article->id]), ['title' => 'Взлом'])->assertNotFound();
    $this->get(route('fastleo.resources.show', ['articles', $article->id]))
        ->assertOk()
        ->assertSee('В корзине')
        ->assertDontSee(route('fastleo.resources.edit', ['articles', $article->id]));
});

it('follows the model policy: a published article cannot be deleted', function (): void {
    $article = Article::factory()->published()->create();

    $this->get(route('fastleo.resources.edit', ['articles', $article->id]))
        ->assertOk()
        ->assertDontSee('name="_method" value="DELETE"', false);
    $this->delete(route('fastleo.resources.destroy', ['articles', $article->id]))->assertForbidden();
    $this->assertNotSoftDeleted($article);
});

it('shows the open on site button', function (): void {
    $article = Article::factory()->create();

    $this->get(route('fastleo.resources.edit', ['articles', $article->id]))
        ->assertSee('Открыть на сайте')
        ->assertSee(url('/articles/'.$article->id));
});
