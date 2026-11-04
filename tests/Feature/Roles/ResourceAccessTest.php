<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Support\Menu;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;

beforeEach(function (): void {
    useRoles();
});

/**
 * Войти администратором с ролью, у которой только перечисленные права вида «articles.view».
 *
 * @param  list<string>  $permissions
 */
function signInWithPermissions(array $permissions): void
{
    test()->actingAs(roleAdmin(role('custom', $permissions)), Fastleo::GUARD);
}

it('lets the owner open and change an article', function (): void {
    $this->actingAs(roleAdmin(role('fastleo-owner')), Fastleo::GUARD);
    $article = Article::factory()->create();

    $this->get(route('fastleo.resources.index', 'articles'))->assertOk();
    $this->get(route('fastleo.resources.edit', ['articles', $article->id]))->assertOk();
    $this->delete(route('fastleo.resources.destroy', ['articles', $article->id]))->assertRedirect();
});

it('lets an observer only look', function (): void {
    signInWithPermissions(['articles.view']);
    $article = Article::factory()->create();

    $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertDontSee(route('fastleo.resources.create', 'articles'), false);
    $this->get(route('fastleo.resources.show', ['articles', $article->id]))->assertOk();
    $this->get(route('fastleo.resources.create', 'articles'))->assertForbidden();
    $this->post(route('fastleo.resources.store', 'articles'), articleData())->assertForbidden();
    $this->get(route('fastleo.resources.edit', ['articles', $article->id]))->assertForbidden();
    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['title' => 'Новое']))->assertForbidden();
    $this->delete(route('fastleo.resources.destroy', ['articles', $article->id]))->assertForbidden();

    expect($article->fresh()->title)->not->toBe('Новое')
        ->and(Article::query()->count())->toBe(1);
});

it('lets an editor change but not delete', function (): void {
    signInWithPermissions(['articles.view', 'articles.update']);
    $article = Article::factory()->create();

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['title' => 'Новое']))->assertRedirect();
    $this->delete(route('fastleo.resources.destroy', ['articles', $article->id]))->assertForbidden();

    expect($article->fresh()->title)->toBe('Новое');
});

it('lets an author create but not change', function (): void {
    signInWithPermissions(['articles.view', 'articles.create']);
    $article = Article::factory()->create(['title' => 'Старое']);

    $this->post(route('fastleo.resources.store', 'articles'), articleData(['title' => 'Моя']))->assertRedirect();
    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['title' => 'Чужое']))->assertForbidden();

    expect(Article::query()->where('title', 'Моя')->exists())->toBeTrue()
        ->and($article->fresh()->title)->toBe('Старое');
});

it('hides a section without the view right from the menu and closes it', function (): void {
    signInWithPermissions(['categories.view']);

    $urls = collect(Menu::groups())->pluck('items')->flatten(1)->pluck('url')->all();

    expect($urls)->toContain(route('fastleo.resources.index', 'categories'))
        ->not->toContain(route('fastleo.resources.index', 'articles'));
    $this->get(route('fastleo.resources.index', 'articles'))->assertForbidden();
});

it('keeps export and import behind the matrix', function (): void {
    signInWithPermissions(['articles.view']);

    $this->get(route('fastleo.resources.export', 'articles'))->assertOk();
    $this->post(route('fastleo.resources.import', 'articles'))->assertForbidden();
});

it('denies a signed-in admin with no role at all', function (): void {
    $this->actingAs(roleAdmin(), Fastleo::GUARD);

    $this->get(route('fastleo.resources.index', 'articles'))->assertForbidden();
});

it('denies everything to an admin whose model has no roles trait', function (): void {
    config(['auth.providers.users.model' => User::class]);
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->get(route('fastleo.resources.index', 'articles'))->assertForbidden();
});

it('does not change anything when roles are off', function (): void {
    config(['fastleo.roles.enabled' => false]);
    $this->actingAs(roleAdmin(), Fastleo::GUARD);

    $this->get(route('fastleo.resources.index', 'articles'))->assertOk();
});
