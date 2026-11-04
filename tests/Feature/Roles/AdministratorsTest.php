<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Resources\UserResource;
use Fastleo\Resources\Users\UserRoles;
use Spatie\Permission\Models\Role;
use Workbench\App\Models\User;

beforeEach(function (): void {
    useRoles();
    $this->owner = role('fastleo-owner');
});

/**
 * Данные формы администратора.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ownerAdminForm(array $overrides = []): array
{
    return ['name' => 'Иван', 'email' => 'ivan@example.com', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1', 'fastleo_admin' => '1', ...$overrides];
}

it('keeps the administrators section for the owner only', function (): void {
    $this->actingAs(roleAdmin(role('manager', ['users.view', 'users.create', 'users.update', 'users.delete'])), Fastleo::GUARD);
    $victim = roleAdmin($this->owner);

    $this->get(route('fastleo.resources.index', 'users'))->assertForbidden();
    $this->get(route('fastleo.resources.edit', ['users', $victim->id]))->assertForbidden();
    $this->put(route('fastleo.resources.update', ['users', $victim->id]), ownerAdminForm(['email' => $victim->email]))->assertForbidden();
    $this->post(route('fastleo.resources.action', ['users', 'reset-two-factor']), ['ids' => [$victim->id]])->assertForbidden();
    $this->delete(route('fastleo.resources.destroy', ['users', $victim->id]))->assertForbidden();

    expect(password_verify('password', (string) $victim->fresh()->password))->toBeTrue();
});

it('shows the roles as checkboxes and takes all of them away when none is ticked', function (): void {
    $editor = role('editor', ['articles.view']);
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);
    $target = roleAdmin($editor);

    $html = $this->get(route('fastleo.resources.edit', ['users', $target->id]))->assertOk()->getContent();

    expect($html)->toContain('name="roles[]"')->toContain('value="editor"')->not->toContain('<select');

    $this->put(route('fastleo.resources.update', ['users', $target->id]), ownerAdminForm(['email' => $target->email, 'roles' => '']))->assertRedirect();

    expect($target->fresh()->roles)->toBeEmpty();
});

it('shows the roles of every administrator in the list without a query per row', function (): void {
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);
    roleAdmin(role('Редактор'), ['name' => 'Иван']);
    roleAdmin(role('Редактор'));
    Role::findOrCreate('site-only', 'web');

    $this->get(route('fastleo.resources.index', 'users'))->assertOk()->assertSee('fastleo-owner')->assertSee('Редактор');
});

it('lets the owner give a role to an administrator and takes it away again', function (): void {
    role('editor', ['articles.view']);
    role('writer');
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);
    $target = roleAdmin();

    $this->get(route('fastleo.resources.edit', ['users', $target->id]))->assertOk()->assertSee('editor');
    $this->put(route('fastleo.resources.update', ['users', $target->id]), ownerAdminForm(['email' => $target->email, 'roles' => ['editor', 'writer']]))->assertRedirect();

    expect($target->fresh()->roles->pluck('name')->sort()->values()->all())->toBe(['editor', 'writer']);

    $this->put(route('fastleo.resources.update', ['users', $target->id]), ownerAdminForm(['email' => $target->email, 'roles' => ['writer']]))->assertRedirect();

    expect($target->fresh()->roles->pluck('name')->all())->toBe(['writer']);
});

it('does not touch the roles of another guard and ignores unknown ones', function (): void {
    $web = Role::findOrCreate('site-editor', 'web');
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);
    $target = roleAdmin($this->owner);
    $target->assignRole($web);

    $this->put(route('fastleo.resources.update', ['users', $target->id]), ownerAdminForm(['email' => $target->email, 'roles' => ['fastleo-owner']]))->assertRedirect();
    $this->put(route('fastleo.resources.update', ['users', $target->id]), ownerAdminForm(['email' => $target->email, 'roles' => ['site-editor']]))->assertSessionHasErrors('roles.0');

    expect($target->fresh()->roles->pluck('name')->sort()->values()->all())->toBe(['fastleo-owner', 'site-editor']);
});

it('does not leave the site without an owner when the last one loses the role', function (): void {
    $me = roleAdmin($this->owner);
    $this->actingAs($me, Fastleo::GUARD);

    $this->put(route('fastleo.resources.update', ['users', $me->id]), ownerAdminForm(['email' => $me->email, 'password' => '', 'password_confirmation' => '', 'roles' => []]))
        ->assertSessionHasErrors('roles');

    expect($me->fresh()->hasRole('fastleo-owner', Fastleo::GUARD))->toBeTrue();
});

it('does not count an owner who cannot sign in as the owner that remains', function (): void {
    $me = roleAdmin($this->owner);
    roleAdmin($this->owner, ['fastleo_admin' => false]);
    $this->actingAs($me, Fastleo::GUARD);

    $this->put(route('fastleo.resources.update', ['users', $me->id]), ownerAdminForm(['email' => $me->email, 'password' => '', 'password_confirmation' => '', 'roles' => []]))
        ->assertSessionHasErrors('roles');

    expect($me->fresh()->hasRole('fastleo-owner', Fastleo::GUARD))->toBeTrue();
});

it('lets an owner step down when another owner remains', function (): void {
    $me = roleAdmin($this->owner);
    roleAdmin($this->owner);
    $this->actingAs($me, Fastleo::GUARD);

    $this->put(route('fastleo.resources.update', ['users', $me->id]), ownerAdminForm(['email' => $me->email, 'password' => '', 'password_confirmation' => '', 'roles' => []]))
        ->assertRedirect();

    expect($me->fresh()->roles)->toBeEmpty();
});

it('shows no roles field when roles are off', function (): void {
    config(['fastleo.roles.enabled' => false]);
    $this->actingAs(roleAdmin(), Fastleo::GUARD);

    $fields = collect(resolve(UserResource::class)->fields())->map(fn ($field): string => $field->name);

    expect($fields->all())->not->toContain('roles');
});

it('explains that the user model needs the roles trait', function (): void {
    $field = UserRoles::make('roles')->multiple();
    $field->forModel(User::class);

    expect(fn (): array => $field->getOptions())->toThrow(LogicException::class, 'HasRoles');
});
