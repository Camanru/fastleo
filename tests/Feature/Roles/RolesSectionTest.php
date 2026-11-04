<?php

declare(strict_types=1);

use Fastleo\Auth\RoleMatrix;
use Fastleo\Auth\RolePermissions;
use Fastleo\Auth\RolePresets;
use Fastleo\Fastleo;
use Fastleo\Mcp\FastleoServer;
use Fastleo\Mcp\Tools\DescribeResource;
use Fastleo\Notifications\NotificationBotResource;
use Fastleo\Publishing\ChannelResource;
use Fastleo\Publishing\PublicationResource;
use Fastleo\Resources\Roles\PermissionMatrix;
use Fastleo\Support\ResourceInspector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;

beforeEach(function (): void {
    useRoles();
    Fastleo::resources()->flush();
    $this->owner = role('fastleo-owner');
});

it('shows the roles section to the owner and keeps it out of the matrix of other roles', function (): void {
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);

    $this->get(route('fastleo.resources.index', 'roles'))->assertOk()->assertSee('fastleo-owner');

    expect(array_column(RolePermissions::sections(), 'slug'))->not->toContain('roles', 'users')->toContain('articles');
});

it('closes the roles section to everybody but the owner', function (): void {
    $other = role('manager', ['roles.view', 'roles.create', 'roles.update', 'roles.delete']);
    $this->actingAs(roleAdmin($other), Fastleo::GUARD);

    $this->get(route('fastleo.resources.index', 'roles'))->assertForbidden();
    $this->get(route('fastleo.resources.create', 'roles'))->assertForbidden();
    $this->post(route('fastleo.resources.store', 'roles'), ['name' => 'Хитрец', 'permissions' => ['fastleo.articles.view']])->assertForbidden();

    expect(Role::query()->where('name', 'Хитрец')->exists())->toBeFalse();
});

it('is absent while roles are off or the site switched the section off', function (): void {
    config(['fastleo.roles.enabled' => false]);
    Fastleo::resources()->flush();

    expect(Fastleo::resources()->find('roles'))->toBeNull();

    config(['fastleo.roles.enabled' => true, 'fastleo.roles.resource' => null]);
    Fastleo::resources()->flush();

    expect(Fastleo::resources()->find('roles'))->toBeNull();
});

it('creates a role with rights and adds view to every row that has another right', function (): void {
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);

    $this->post(route('fastleo.resources.store', 'roles'), ['name' => 'Редактор статей', 'permissions' => ['fastleo.articles.update', 'fastleo.categories.view']])->assertRedirect();

    $role = Role::query()->where('name', 'Редактор статей')->firstOrFail();

    expect($role->guard_name)->toBe(Fastleo::GUARD)
        ->and($role->permissions->pluck('name')->sort()->values()->all())->toBe(['fastleo.articles.update', 'fastleo.articles.view', 'fastleo.categories.view']);
});

it('gives the new role real rights', function (): void {
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);
    $this->post(route('fastleo.resources.store', 'roles'), ['name' => 'Читатель', 'permissions' => ['fastleo.articles.view']])->assertRedirect();

    $reader = roleAdmin(Role::findByName('Читатель', Fastleo::GUARD));
    Fastleo::guard()->setUser($reader);

    expect(RoleMatrix::allows('viewAny', 'articles'))->toBeTrue()->and(RoleMatrix::allows('create', 'articles'))->toBeFalse();
});

it('shows the rights of a role as ticked boxes and lists the sections', function (): void {
    $role = role('viewer', ['articles.view', 'articles.update']);
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);

    $html = $this->get(route('fastleo.resources.edit', ['roles', $role->id]))->assertOk()->assertSee('Права по разделам')->getContent();

    expect($html)->toContain('value="fastleo.articles.update"')->toContain('value="fastleo.categories.view"')->not->toContain('value="fastleo.roles.view"');
});

it('takes away the rights that were unticked and leaves the rights outside the matrix alone', function (): void {
    $role = role('viewer', ['articles.view', 'articles.update', 'categories.view']);
    $role->givePermissionTo(Permission::findOrCreate('fastleo.reports.export', Fastleo::GUARD));
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);

    $this->put(route('fastleo.resources.update', ['roles', $role->id]), ['name' => 'viewer', 'permissions' => ['fastleo.articles.view']])->assertRedirect();

    expect($role->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['fastleo.articles.view', 'fastleo.reports.export']);
});

it('takes away all rights when the last box is unticked', function (): void {
    $role = role('viewer', ['articles.view']);
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);

    $this->put(route('fastleo.resources.update', ['roles', $role->id]), ['name' => 'viewer', 'permissions' => ''])->assertRedirect();

    expect($role->fresh()->permissions)->toHaveCount(0);
});

it('rejects a right that is not in the matrix', function (): void {
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);

    $this->post(route('fastleo.resources.store', 'roles'), ['name' => 'Подделка', 'permissions' => ['fastleo.roles.delete', 'fastleo.users.update', 'articles.view']])->assertSessionHasErrors(['permissions.0', 'permissions.1', 'permissions.2']);

    expect(Role::query()->where('name', 'Подделка')->exists())->toBeFalse();
});

it('keeps the role under the fastleo guard whatever the request says', function (): void {
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);

    $this->post(route('fastleo.resources.store', 'roles'), ['name' => 'Чужая', 'guard_name' => 'web', 'permissions' => ['fastleo.articles.view']])->assertRedirect();

    expect(Role::query()->where('name', 'Чужая')->value('guard_name'))->toBe(Fastleo::GUARD);
});

it('does not show or change roles of the site guard', function (): void {
    $web = Role::findOrCreate('site-editor', 'web');
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);

    $this->get(route('fastleo.resources.index', 'roles'))->assertOk()->assertDontSee('site-editor');
    $this->get(route('fastleo.resources.edit', ['roles', $web->id]))->assertNotFound();
    $this->delete(route('fastleo.resources.destroy', ['roles', $web->id]))->assertNotFound();

    expect(Role::query()->whereKey($web->id)->exists())->toBeTrue();
});

it('wants a name that is unique among the roles of the guard', function (): void {
    role('viewer');
    Role::findOrCreate('shared', 'web');
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);

    $this->post(route('fastleo.resources.store', 'roles'), ['name' => 'viewer'])->assertSessionHasErrors('name');
    $this->post(route('fastleo.resources.store', 'roles'), ['name' => 'shared'])->assertRedirect();

    expect(Role::query()->where('name', 'shared')->count())->toBe(2);
});

it('lets the owner role be looked at but not changed or deleted', function (): void {
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);

    $this->get(route('fastleo.resources.show', ['roles', $this->owner->id]))->assertOk();
    $this->get(route('fastleo.resources.edit', ['roles', $this->owner->id]))->assertForbidden();
    $this->put(route('fastleo.resources.update', ['roles', $this->owner->id]), ['name' => 'обычная'])->assertForbidden();
    $this->delete(route('fastleo.resources.destroy', ['roles', $this->owner->id]))->assertForbidden();

    expect($this->owner->fresh()->name)->toBe('fastleo-owner');
});

it('deletes an ordinary role and the administrators lose it', function (): void {
    $role = role('viewer', ['articles.view']);
    $admin = roleAdmin($role);
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);

    $this->delete(route('fastleo.resources.destroy', ['roles', $role->id]))->assertRedirect();

    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse()->and($admin->fresh()->roles)->toHaveCount(0);
});

it('describes the rights of the role on the page of a role', function (): void {
    $role = role('viewer', ['articles.view', 'articles.update']);
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);

    $this->get(route('fastleo.resources.show', ['roles', $role->id]))->assertOk()->assertSee('просмотр, изменение', false);
});

it('creates the ready roles once and leaves changed ones alone', function (): void {
    $this->artisan('fastleo:roles', ['--presets' => true])->expectsOutputToContain('Созданы готовые роли')->assertSuccessful();

    $viewer = Role::findByName('Наблюдатель', Fastleo::GUARD);
    $editor = Role::findByName('Редактор', Fastleo::GUARD);

    expect($viewer->permissions->pluck('name')->every(fn (string $name): bool => str_ends_with($name, '.view')))->toBeTrue()
        ->and($editor->hasPermissionTo('fastleo.articles.update', Fastleo::GUARD))->toBeTrue()
        ->and($editor->hasPermissionTo('fastleo.articles.delete', Fastleo::GUARD))->toBeFalse()
        ->and(Role::findByName('Администратор', Fastleo::GUARD)->hasPermissionTo('fastleo.articles.delete', Fastleo::GUARD))->toBeTrue();

    $viewer->givePermissionTo('fastleo.articles.delete');
    $this->artisan('fastleo:roles', ['--presets' => true])->expectsOutputToContain('уже есть')->assertSuccessful();

    expect(Role::findByName('Наблюдатель', Fastleo::GUARD)->hasPermissionTo('fastleo.articles.delete', Fastleo::GUARD))->toBeTrue()
        ->and(Role::query()->where('guard_name', Fastleo::GUARD)->count())->toBe(4);
});

it('gives the sections that deliver messages only to the administrator preset', function (): void {
    config([
        'fastleo.notifications.bots' => NotificationBotResource::class,
        'fastleo.publishing.channels' => ChannelResource::class,
        'fastleo.publishing.publications' => PublicationResource::class,
    ]);
    Fastleo::resources()->flush();

    $this->artisan('fastleo:roles', ['--presets' => true])->assertSuccessful();

    $names = fn (string $role): array => Role::findByName($role, Fastleo::GUARD)->permissions->pluck('name')->filter(fn (string $name): bool => preg_match('/\.(channels|publications|notification-bots)\./', $name) === 1)->all();

    expect($names('Редактор'))->toBe([])
        ->and($names('Наблюдатель'))->toBe([])
        ->and($names('Администратор'))->not->toBe([])
        ->and(Role::findByName('Редактор', Fastleo::GUARD)->hasPermissionTo('fastleo.articles.update', Fastleo::GUARD))->toBeTrue();
});

it('keeps owner-only sections out of the ready roles', function (): void {
    $this->artisan('fastleo:roles', ['--presets' => true])->assertSuccessful();

    expect(Permission::query()->where('name', 'like', 'fastleo.users.%')->orWhere('name', 'like', 'fastleo.roles.%')->count())->toBe(0);
});

it('tells inspect about the roles and their rights', function (): void {
    role('viewer', ['articles.view', 'articles.update']);

    $this->artisan('fastleo:inspect', ['--json' => true])->expectsOutputToContain('"viewer"')->assertSuccessful();
    $this->artisan('fastleo:inspect')->expectsOutputToContain('Роль viewer')->assertSuccessful();
});

it('does not tell inspect about roles while they are off', function (): void {
    config(['fastleo.roles.enabled' => false]);

    $this->artisan('fastleo:inspect', ['--json' => true])->doesntExpectOutputToContain('"owner"')->assertSuccessful();
});

it('tells an agent which rights its role has in the section', function (): void {
    $user = roleAdmin(role('editor', ['articles.view', 'articles.update']));

    FastleoServer::actingAs($user, Fastleo::GUARD)->tool(DescribeResource::class, ['resource' => 'articles'])
        ->assertSee('"role_rights":{"view":true,"create":false,"update":true,"delete":false}');
});

it('says nothing about the rights of a role that has none', function (): void {
    $role = role('empty');
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);

    $this->get(route('fastleo.resources.show', ['roles', $role->id]))->assertOk();

    expect(new PermissionMatrix('permissions')->display($role))->toBeNull();
});

it('has no rights for a role that is not saved yet and writes nothing to a model without rights', function (): void {
    RolePermissions::sync(new Article(), ['fastleo.articles.view']);

    expect(RolePermissions::current(new Role()))->toBe([])
        ->and(RolePermissions::current(new Article()))->toBe([]);
});

it('creates no ready roles while the user model has no roles trait', function (): void {
    config(['auth.providers.users.model' => User::class]);

    expect(RolePresets::create())->toBe([]);
});

it('does not tell inspect about roles when the tables are missing', function (): void {
    foreach (['model_has_roles', 'model_has_permissions', 'role_has_permissions', 'roles', 'permissions'] as $table) {
        Schema::drop(config()->string('permission.table_names.'.$table));
    }

    expect(new ResourceInspector()->roles())->toBeNull();
})->skip(fn (): bool => in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true), 'DROP TABLE в MySQL неявно закрывает транзакцию теста и оставляет базу без таблицы');

it('forgets the old rights again after the role is saved, when a parallel request has cached them in between', function (): void {
    $role = role('viewer', ['articles.view', 'articles.update']);
    $viewer = roleAdmin($role);
    // Параллельный запрос между записью роли и записью её прав снова кладёт старые права в кеш.
    Role::saved(fn () => resolve(PermissionRegistrar::class)->getPermissions());

    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);
    $this->put(route('fastleo.resources.update', ['roles', $role->id]), ['name' => 'viewer2', 'permissions' => ['fastleo.articles.view']])->assertRedirect();

    $cached = resolve(PermissionRegistrar::class)->getPermissions(['name' => 'fastleo.articles.update'])->first();

    expect($cached?->roles->pluck('name')->all())->toBe([])
        ->and($viewer->fresh()->checkPermissionTo('fastleo.articles.update', Fastleo::GUARD))->toBeFalse();
});
