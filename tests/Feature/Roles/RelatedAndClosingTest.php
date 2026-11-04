<?php

declare(strict_types=1);

use Fastleo\Auth\RoleMatrix;
use Fastleo\Closing\SiteClosing;
use Fastleo\Fastleo;
use Fastleo\Mcp\FastleoServer;
use Fastleo\Mcp\Tools\ListRecords;
use Spatie\Permission\Models\Permission;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useRoles();
    Fastleo::resources()->flush();
});

it('keeps the site closing for the owner only', function (): void {
    config(['fastleo.closing.enabled' => true]);
    $this->actingAs(roleAdmin(role('editor', ['articles.view', 'articles.update'])), Fastleo::GUARD);

    $this->get(route('fastleo.settings.edit', 'closing'))->assertForbidden();
    $this->put(route('fastleo.settings.update', 'closing'), ['closed' => '1', 'message' => 'взлом'])->assertForbidden();

    expect(SiteClosing::closed())->toBeFalse();
});

it('opens the site closing to the owner', function (): void {
    config(['fastleo.closing.enabled' => true]);
    $this->actingAs(roleAdmin(role('fastleo-owner')), Fastleo::GUARD);

    $this->get(route('fastleo.settings.edit', 'closing'))->assertOk();
});

it('puts no labels of a closed related section into the form, the list or the filter', function (): void {
    $category = Category::factory()->create(['title' => 'СекретнаяКатегорияXYZ']);
    $article = Article::factory()->create(['category_id' => null]);
    $this->actingAs(roleAdmin(role('editor', ['articles.view', 'articles.create', 'articles.update'])), Fastleo::GUARD);

    foreach ([route('fastleo.resources.index', 'articles'), route('fastleo.resources.create', 'articles'), route('fastleo.resources.edit', ['articles', $article->id])] as $url) {
        $this->get($url)->assertOk()->assertDontSee('СекретнаяКатегорияXYZ');
    }

    $this->get(route('fastleo.resources.index', ['articles', 'filter' => ['category_id' => $category->id]]))->assertOk()->assertDontSee('СекретнаяКатегорияXYZ');
});

it('tells under the grey field why the record cannot be chosen', function (): void {
    $this->actingAs(roleAdmin(role('editor', ['articles.view', 'articles.create'])), Fastleo::GUARD);

    $this->get(route('fastleo.resources.create', 'articles'))->assertOk()->assertSee('Нет доступа к разделу «Категории»', false);
});

it('does not tell it when the related section is open', function (): void {
    $this->actingAs(roleAdmin(role('editor', ['articles.view', 'articles.create', 'categories.view'])), Fastleo::GUARD);

    $this->get(route('fastleo.resources.create', 'articles'))->assertOk()->assertDontSee('Нет доступа к разделу «Категории»', false);
});

it('puts the labels of an open related section into the form', function (): void {
    Category::factory()->create(['title' => 'ОткрытаяКатегорияXYZ']);
    $this->actingAs(roleAdmin(role('editor', ['articles.view', 'articles.create', 'categories.view'])), Fastleo::GUARD);

    $this->get(route('fastleo.resources.create', 'articles'))->assertOk()->assertSee('ОткрытаяКатегорияXYZ');
});

it('refuses to attach a record of a closed related section and keeps the old one', function (): void {
    $old = Category::factory()->create();
    $closed = Category::factory()->create();
    $article = Article::factory()->create(['category_id' => $old->id]);
    $this->actingAs(roleAdmin(role('editor', ['articles.view', 'articles.update'])), Fastleo::GUARD);

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['title' => 'Новое', 'category_id' => $closed->id]))->assertRedirect();

    expect($article->fresh()->title)->toBe('Новое')->and($article->fresh()->category_id)->toBe($old->id);
});

it('lets a role with the related section attach its records', function (): void {
    $new = Category::factory()->create();
    $article = Article::factory()->create(['category_id' => null]);
    $this->actingAs(roleAdmin(role('editor', ['articles.view', 'articles.update', 'categories.view'])), Fastleo::GUARD);

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['category_id' => $new->id]))->assertRedirect();

    expect($article->fresh()->category_id)->toBe($new->id);
});

it('does not close a relation while nobody is signed in', function (): void {
    expect(RoleMatrix::closesRelated(new Category))->toBeFalse();
});

it('takes the fastleo guard of the right even when the default guard is web', function (): void {
    config(['auth.defaults.guard' => 'web']);
    $user = roleAdmin();
    $user->givePermissionTo(Permission::findOrCreate('fastleo.articles.view', 'web'));
    Fastleo::guard()->setUser($user);

    expect(RoleMatrix::allows('viewAny', 'articles'))->toBeFalse();

    FastleoServer::actingAs($user, Fastleo::GUARD)->tool(ListRecords::class, ['resource' => 'articles'])->assertHasErrors();
});

it('counts an unknown ability as a change', function (): void {
    $this->actingAs(roleAdmin(role('reader', ['articles.view'])), Fastleo::GUARD);

    expect(RoleMatrix::allows('publish', 'articles'))->toBeFalse();

    $this->actingAs(roleAdmin(role('writer', ['articles.view', 'articles.update'])), Fastleo::GUARD);

    expect(RoleMatrix::allows('publish', 'articles'))->toBeTrue();
});
