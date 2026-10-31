<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Database\QueryException;
use Illuminate\Testing\TestResponse;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\Feedback;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Запустить встроенное действие раздела.
 *
 * @param  list<string>  $ids
 */
function runBuiltIn(string $resource, string $action, array $ids): TestResponse
{
    return test()->from(route('fastleo.resources.index', $resource))
        ->post(route('fastleo.resources.action', [$resource, $action]), ['ids' => $ids]);
}

it('moves the selected records to the trash', function (): void {
    $articles = Article::factory()->count(3)->create();

    runBuiltIn('articles', 'delete', [(string) $articles[0]->id, (string) $articles[1]->id])
        ->assertSessionHas('fastleo.success', 'Удалено: 2.');

    expect(Article::query()->pluck('id')->all())->toBe([$articles[2]->id])
        ->and(Article::onlyTrashed()->count())->toBe(2);
});

it('restores and deletes forever only records from the trash', function (): void {
    [$first, $second, $alive] = Article::factory()->count(3)->create();
    $first->delete();
    $second->delete();

    runBuiltIn('articles', 'restore', [(string) $first->id])->assertSessionHas('fastleo.success', 'Восстановлено: 1.');
    runBuiltIn('articles', 'force-delete', [(string) $alive->id])->assertNotFound();
    runBuiltIn('articles', 'force-delete', [(string) $second->id])->assertSessionHas('fastleo.success', 'Удалено: 1.');

    expect(Article::query()->pluck('id')->sort()->values()->all())->toBe([$first->id, $alive->id])
        ->and(Article::withTrashed()->find($second->id))->toBeNull();
});

it('keeps a tree node with children and deletes the rest', function (): void {
    useFixtureResources('Filters');
    $parent = Category::factory()->create();
    $child = Category::factory()->create(['parent_id' => $parent->id]);
    $single = Category::factory()->create();

    runBuiltIn('categories', 'delete', [(string) $parent->id, (string) $single->id])
        ->assertSessionHas('fastleo.success', 'Удалено: 1. Не выполнено для 1: Сначала удалите или перенесите вложенные записи.');

    expect(Category::query()->pluck('id')->sort()->values()->all())->toBe([$parent->id, $child->id]);
});

it('reports when none of the records could be deleted', function (): void {
    useFixtureResources('Filters');
    $parent = Category::factory()->create();
    Category::factory()->create(['parent_id' => $parent->id]);

    runBuiltIn('categories', 'delete', [(string) $parent->id])->assertSessionHas('fastleo.error');

    expect(Category::query()->count())->toBe(2);
});

it('offers no deletion in a read-only section', function (): void {
    $feedback = Feedback::factory()->create();

    $this->get(route('fastleo.resources.index', 'feedback'))
        ->assertOk()
        ->assertSee(route('fastleo.resources.action', ['feedback', 'mark-read']), false)
        ->assertDontSee(route('fastleo.resources.action', ['feedback', 'delete']), false);
    runBuiltIn('feedback', 'delete', [(string) $feedback->id])->assertForbidden();

    expect(Feedback::query()->count())->toBe(1);
});

it('reports records the model refused to delete', function (): void {
    $article = Article::factory()->create();
    Article::deleting(fn (): bool => false);

    runBuiltIn('articles', 'delete', [(string) $article->id])->assertSessionHas('fastleo.error', fn (string $message): bool => str_contains($message, 'Не выполнено для 1'));

    expect($article->fresh()?->trashed())->toBeFalse();
});

it('explains why the database refused to delete a record and goes on with the rest', function (): void {
    [$blocked, $free] = Article::factory()->count(2)->create();
    Article::deleting(function (Article $article) use ($blocked): void {
        if ($article->is($blocked)) {
            throw new QueryException('sqlite', 'update articles', [], new PDOException('FOREIGN KEY constraint failed'));
        }
    });

    runBuiltIn('articles', 'delete', [(string) $blocked->id, (string) $free->id])
        ->assertSessionHas('fastleo.success', fn (string $message): bool => str_starts_with($message, 'Удалено: 1.') && str_contains($message, 'Не выполнено для 1'));

    expect($blocked->fresh()?->trashed())->toBeFalse()->and($free->fresh()?->trashed())->toBeTrue();
});

it('reports records the model refused to restore', function (): void {
    $article = Article::factory()->create();
    $article->delete();
    Article::restoring(fn (): bool => false);

    runBuiltIn('articles', 'restore', [(string) $article->id])->assertSessionHas('fastleo.error', fn (string $message): bool => str_contains($message, 'Не выполнено для 1'));

    expect(Article::withTrashed()->find($article->id)?->trashed())->toBeTrue();
});
