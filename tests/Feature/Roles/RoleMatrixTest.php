<?php

declare(strict_types=1);

use Fastleo\Auth\RoleMatrix;
use Fastleo\Fastleo;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Workbench\App\Models\User;

beforeEach(function (): void {
    useRoles();
    $this->owner = role('fastleo-owner');
});

it('lets everything through when roles are off', function (): void {
    config(['fastleo.roles.enabled' => false]);

    expect(RoleMatrix::allows('delete', 'articles'))->toBeTrue()
        ->and(RoleMatrix::allowsOwnerOnly())->toBeTrue()
        ->and(RoleMatrix::isOwner())->toBeFalse();
});

it('denies when nobody is signed in', function (): void {
    expect(RoleMatrix::allows('viewAny', 'articles'))->toBeFalse()
        ->and(RoleMatrix::isOwner())->toBeFalse();
});

it('denies an admin whose model has no roles trait', function (): void {
    config(['auth.providers.users.model' => User::class]);
    $this->actingAs(admin(), Fastleo::GUARD);

    expect(RoleMatrix::allows('viewAny', 'articles'))->toBeFalse()
        ->and(RoleMatrix::isOwner())->toBeFalse()
        ->and(RoleMatrix::allowsOwnerOnly())->toBeFalse();
});

it('denies an admin without roles', function (): void {
    $this->actingAs(roleAdmin(), Fastleo::GUARD);

    expect(RoleMatrix::allows('viewAny', 'articles'))->toBeFalse();
});

it('lets the owner through everything', function (): void {
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);

    expect(RoleMatrix::allows('forceDelete', 'articles'))->toBeTrue()
        ->and(RoleMatrix::isOwner())->toBeTrue()
        ->and(RoleMatrix::allowsOwnerOnly())->toBeTrue();
});

it('maps every ability to a group of the matrix', function (string $ability, string $group): void {
    $this->actingAs(roleAdmin(role('r', ['articles.view', 'articles.'.$group])), Fastleo::GUARD);

    expect(RoleMatrix::allows($ability, 'articles'))->toBeTrue();
})->with([
    ['viewAny', 'view'], ['view', 'view'], ['export', 'view'],
    ['create', 'create'], ['import', 'create'],
    ['update', 'update'], ['reorder', 'update'], ['restore', 'update'], ['custom', 'update'],
    ['delete', 'delete'], ['forceDelete', 'delete'],
]);

it('does not give other groups', function (): void {
    $this->actingAs(roleAdmin(role('editor', ['articles.view', 'articles.update'])), Fastleo::GUARD);

    expect(RoleMatrix::allows('update', 'articles'))->toBeTrue()
        ->and(RoleMatrix::allows('delete', 'articles'))->toBeFalse()
        ->and(RoleMatrix::allows('create', 'articles'))->toBeFalse()
        ->and(RoleMatrix::allows('viewAny', 'categories'))->toBeFalse();
});

it('requires view for any other group', function (): void {
    $this->actingAs(roleAdmin(role('writer', ['articles.create'])), Fastleo::GUARD);

    expect(RoleMatrix::allows('create', 'articles'))->toBeFalse();
});

it('answers false, not an exception, for a permission that does not exist', function (): void {
    $this->actingAs(roleAdmin(role('empty')), Fastleo::GUARD);

    expect(RoleMatrix::allows('viewAny', 'brand-new-section'))->toBeFalse();
});

it('takes only roles of the fastleo guard', function (): void {
    $web = Role::findOrCreate('fastleo-owner', 'web');
    $user = roleAdmin();
    $user->assignRole($web);
    $this->actingAs($user, Fastleo::GUARD);

    expect(RoleMatrix::isOwner())->toBeFalse();
});

it('takes only permissions of the fastleo guard', function (): void {
    $web = Role::findOrCreate('editor', 'web');
    $web->givePermissionTo(Permission::findOrCreate('fastleo.articles.view', 'web'));
    $user = roleAdmin();
    $user->assignRole($web);
    $this->actingAs($user, Fastleo::GUARD);

    expect(RoleMatrix::allows('viewAny', 'articles'))->toBeFalse();
});

it('reads the owner role name from config', function (): void {
    config(['fastleo.roles.owner' => 'chief']);
    $this->actingAs(roleAdmin(role('chief')), Fastleo::GUARD);

    expect(RoleMatrix::isOwner())->toBeTrue()
        ->and(RoleMatrix::ownerRole())->toBe('chief')
        ->and(Gate::allows('anything'))->toBeFalse();
});

it('tells whether the admin can change anything at all', function (): void {
    expect(RoleMatrix::canWrite())->toBeFalse();

    config(['auth.providers.users.model' => User::class]);
    $this->actingAs(admin(), Fastleo::GUARD);
    expect(RoleMatrix::canWrite())->toBeFalse();
});

it('counts the owner and anybody with create or update as able to change', function (): void {
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);
    expect(RoleMatrix::canWrite())->toBeTrue();
});

it('does not count a view-only role as able to change', function (): void {
    $this->actingAs(roleAdmin(role('observer', ['articles.view'])), Fastleo::GUARD);
    expect(RoleMatrix::canWrite())->toBeFalse();
});

it('counts roles off as able to change', function (): void {
    config(['fastleo.roles.enabled' => false]);
    expect(RoleMatrix::canWrite())->toBeTrue();
});
