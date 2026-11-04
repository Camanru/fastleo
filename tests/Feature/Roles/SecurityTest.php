<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Imports\Import;
use Fastleo\Mcp\FastleoServer;
use Fastleo\Mcp\Tools\CreateRecord;
use Fastleo\Mcp\Tools\ListRecords;
use Fastleo\Mcp\Tools\ListResources;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useRoles();
});

it('turns bulk actions into per-row checks of the matrix', function (): void {
    $article = Article::factory()->create();
    $this->actingAs(roleAdmin(role('editor', ['articles.view', 'articles.update'])), Fastleo::GUARD);

    $this->post(route('fastleo.resources.action', ['articles', 'delete']), ['ids' => [$article->id]])->assertForbidden();

    expect(Article::query()->count())->toBe(1);
});

it('lets the matrix decide what the search shows', function (): void {
    Article::factory()->create(['title' => 'Вентиляция квартиры']);
    Category::factory()->create(['title' => 'Вентиляция общая']);
    $this->actingAs(roleAdmin(role('reader', ['categories.view'])), Fastleo::GUARD);

    $found = collect($this->getJson(route('fastleo.search', ['q' => 'Вентиляц']))->assertOk()->json('groups'))->flatMap(fn (array $group): array => $group['items'])->pluck('title');

    expect($found->implode('|'))->toContain('Вентиляция общая')->not->toContain('Вентиляция квартиры');
});

it('shows an agent only the sections the matrix allows', function (): void {
    $user = roleAdmin(role('reader', ['categories.view']));

    FastleoServer::actingAs($user, Fastleo::GUARD)->tool(ListResources::class)->assertSee('"resource":"categories"')->assertDontSee('"resource":"articles"');
    FastleoServer::actingAs($user, Fastleo::GUARD)->tool(ListRecords::class, ['resource' => 'articles'])->assertHasErrors();
    FastleoServer::actingAs($user, Fastleo::GUARD)->tool(CreateRecord::class, ['resource' => 'categories', 'data' => ['title' => 'Взлом']])->assertHasErrors();

    expect(Category::query()->where('title', 'Взлом')->exists())->toBeFalse();
});

it('checks every imported row against the matrix of the user who uploaded the file', function (): void {
    Storage::fake('local');
    useFixtureResources('Imports');
    $existing = Category::factory()->create(['code' => 'old', 'title' => 'Старая']);
    $this->actingAs(roleAdmin(role('importer', ['categories.view', 'categories.create'])), Fastleo::GUARD);

    $this->post(route('fastleo.resources.import', 'categories'), [
        'file' => UploadedFile::fake()->createWithContent('categories.csv', "Код;Название\nold;Взлом\nnew;Новая\n"),
    ]);

    expect(Import::query()->latest('id')->firstOrFail()->only('updated', 'created', 'skipped'))->toBe(['updated' => 0, 'created' => 1, 'skipped' => 1])
        ->and($existing->fresh()?->title)->toBe('Старая');
});

it('counts a direct permission like a role permission', function (): void {
    $user = roleAdmin();
    $user->givePermissionTo(Permission::findOrCreate('fastleo.articles.view', Fastleo::GUARD));
    $this->actingAs($user, Fastleo::GUARD);

    $this->get(route('fastleo.resources.index', 'articles'))->assertOk();
    $this->get(route('fastleo.resources.create', 'articles'))->assertForbidden();
});

it('keeps a permission of the web guard from opening anything', function (): void {
    $user = roleAdmin();
    $user->givePermissionTo(Permission::findOrCreate('fastleo.articles.view', 'web'));
    $this->actingAs($user, Fastleo::GUARD);

    $this->get(route('fastleo.resources.index', 'articles'))->assertForbidden();
});

it('survives wildcard permissions without letting the web guard in', function (): void {
    config(['permission.enable_wildcard_permission' => true]);
    $user = roleAdmin();
    $user->givePermissionTo(Permission::findOrCreate('fastleo.articles.*', 'web'));
    $this->actingAs($user, Fastleo::GUARD);

    $this->get(route('fastleo.resources.index', 'articles'))->assertForbidden();
});

it('reads the rights of an agent anew on every call', function (): void {
    $user = roleAdmin(role('reader', ['categories.view']));
    FastleoServer::actingAs($user, Fastleo::GUARD)->tool(ListRecords::class, ['resource' => 'categories'])->assertOk();

    // Мимо моделей spatie (у них свой сброс кеша): так правит права другой процесс.
    DB::table(config()->string('permission.table_names.role_has_permissions'))->delete();

    FastleoServer::actingAs($user, Fastleo::GUARD)->tool(ListRecords::class, ['resource' => 'categories'])->assertHasErrors();
});
