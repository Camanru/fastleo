<?php

declare(strict_types=1);

use Fastleo\Auth\RoleMatrix;
use Fastleo\Fastleo;
use Fastleo\Files\FileStore;
use Fastleo\Logs\LogReader;
use Fastleo\Support\CacheCleaner;
use Fastleo\Tests\Fixtures\Roles\RoleUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useRoles();
    Storage::fake('public');
});

it('closes settings and own pages to everyone but the owner', function (): void {
    $settings = array_key_first(Fastleo::resources()->settings());
    $page = array_key_first(array_diff_key(Fastleo::resources()->pages(), ['softnetica' => true]));
    $this->actingAs(roleAdmin(role('editor', ['articles.view', 'articles.update'])), Fastleo::GUARD);

    $this->get(route('fastleo.settings.edit', $settings))->assertForbidden();
    $this->put(route('fastleo.settings.update', $settings), [])->assertForbidden();
    $this->get(route('fastleo.pages.show', $page))->assertForbidden();
});

it('opens settings and own pages to the owner', function (): void {
    $this->actingAs(roleAdmin(role('fastleo-owner')), Fastleo::GUARD);

    $this->get(route('fastleo.settings.edit', array_key_first(Fastleo::resources()->settings())))->assertOk();
    $this->get(route('fastleo.pages.show', array_key_first(array_diff_key(Fastleo::resources()->pages(), ['softnetica' => true]))))->assertOk();
});

it('closes the file manager, logs and cache clearing to everyone but the owner', function (): void {
    $this->actingAs(roleAdmin(role('editor', ['articles.view', 'articles.update'])), Fastleo::GUARD);

    expect(FileStore::canManage())->toBeFalse()
        ->and(LogReader::canView())->toBeFalse()
        ->and(CacheCleaner::canClear())->toBeFalse();
    $this->get(route('fastleo.files.index'))->assertForbidden();
    $this->get(route('fastleo.logs.index'))->assertForbidden();
    $this->post(route('fastleo.dashboard.clear-cache'))->assertForbidden();

    $this->actingAs(roleAdmin(role('fastleo-owner')), Fastleo::GUARD);

    expect(FileStore::canManage())->toBeTrue()
        ->and(LogReader::canView())->toBeTrue()
        ->and(CacheCleaner::canClear())->toBeTrue();
});

it('leaves the decision to the site Gate when it defines one', function (): void {
    Gate::define('manageFastleoFiles', fn (): bool => true);
    Gate::define('viewFastleoLogs', fn (): bool => false);
    $this->actingAs(roleAdmin(role('editor', ['articles.view'])), Fastleo::GUARD);

    expect(FileStore::canManage())->toBeTrue()
        ->and(LogReader::canView())->toBeFalse();
});

it('lets only those who can change something upload, crop and make folders', function (): void {
    $this->actingAs(roleAdmin(role('observer', ['articles.view'])), Fastleo::GUARD);

    $this->postJson(route('fastleo.files.upload'), ['folder' => 'uploads', 'files' => [UploadedFile::fake()->image('a.jpg')]])->assertForbidden();
    $this->postJson(route('fastleo.files.folder'), ['folder' => 'uploads', 'name' => 'new'])->assertForbidden();
    $this->postJson(route('fastleo.files.crop'), ['path' => 'uploads/a.jpg', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1])->assertForbidden();
    $this->get(route('fastleo.files.index', ['picker' => 'field']))->assertForbidden();
    Storage::disk('public')->assertDirectoryEmpty('uploads');
});

it('lets an editor upload and pick files', function (): void {
    $this->actingAs(roleAdmin(role('editor', ['articles.view', 'articles.update'])), Fastleo::GUARD);

    $this->postJson(route('fastleo.files.upload'), ['folder' => 'uploads', 'files' => [UploadedFile::fake()->image('a.jpg')]])->assertOk();
    $this->get(route('fastleo.files.index', ['picker' => 'field']))->assertOk();
});

it('does not count a right without view as a right to change', function (): void {
    $this->actingAs(roleAdmin(role('odd', ['articles.update'])), Fastleo::GUARD);

    $this->postJson(route('fastleo.files.folder'), ['folder' => 'uploads', 'name' => 'new'])->assertForbidden();
});

it('does not hand out labels of a related section the role cannot view', function (): void {
    Category::factory()->create(['title' => 'Секретная категория']);
    $this->actingAs(roleAdmin(role('editor', ['articles.view', 'articles.update'])), Fastleo::GUARD);

    $this->getJson(route('fastleo.resources.options', ['articles', 'category_id']))->assertNotFound();
    $this->getJson(route('fastleo.resources.filter-options', ['articles', 'category_id']))->assertForbidden();
});

it('hands out labels of a related section the role can view', function (): void {
    Category::factory()->create(['title' => 'Секретная категория']);
    $this->actingAs(roleAdmin(role('editor2', ['articles.view', 'articles.update', 'categories.view'])), Fastleo::GUARD);

    $this->getJson(route('fastleo.resources.options', ['articles', 'category_id']))->assertOk()->assertJsonPath('options.0.label', 'Секретная категория');
    $this->getJson(route('fastleo.resources.filter-options', ['articles', 'category_id']))->assertOk();
});

it('leaves the related sections alone when roles are off', function (): void {
    config(['fastleo.roles.enabled' => false]);
    $this->actingAs(roleAdmin(), Fastleo::GUARD);

    $this->getJson(route('fastleo.resources.options', ['articles', 'category_id']))->assertOk();
});

it('does not hand out labels of the administrators section even to a role with the right to view it', function (): void {
    $this->actingAs(roleAdmin(role('curious', ['users.view'])), Fastleo::GUARD);

    expect(RoleMatrix::allows('viewAny', 'users'))->toBeTrue()
        ->and(RoleMatrix::allowsRelated(new RoleUser()))->toBeFalse();
});

it('hides the server status from everyone but the owner', function (): void {
    $this->actingAs(roleAdmin(role('reader', ['articles.view'])), Fastleo::GUARD);
    $this->get(route('fastleo.dashboard'))->assertOk()->assertDontSee('Состояние сервера');
});

it('shows the server status to the owner', function (): void {
    $this->actingAs(roleAdmin(role('fastleo-owner')), Fastleo::GUARD);
    $this->get(route('fastleo.dashboard'))->assertOk()->assertSee('Состояние сервера');
});
