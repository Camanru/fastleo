<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Support\Doctor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Workbench\App\Models\User;

beforeEach(function (): void {
    useRoles();
});

/**
 * Проблемы `fastleo:doctor` по ролям (сообщения).
 *
 * @return list<string>
 */
function roleProblems(): array
{
    return collect(new Doctor()->run())->where('scope', 'roles')->pluck('message')->values()->all();
}

it('creates the owner role and hands it to the named administrator', function (): void {
    $admin = roleAdmin(null, ['email' => 'boss@example.com']);

    $this->artisan('fastleo:roles', ['--owner' => ' Boss@Example.com '])
        ->expectsOutputToContain('Пользователю boss@example.com выдана роль fastleo-owner.')
        ->assertSuccessful();

    expect($admin->fresh()->hasRole('fastleo-owner', Fastleo::GUARD))->toBeTrue()
        ->and(Role::query()->where('name', 'fastleo-owner')->where('guard_name', Fastleo::GUARD)->count())->toBe(1);
});

it('does not promote anybody on a rerun without --owner', function (): void {
    $admin = roleAdmin(null, ['email' => 'plain@example.com']);

    $this->artisan('fastleo:roles')->expectsOutputToContain('Ни одного владельца с доступом в панель нет.')->assertSuccessful();
    $this->artisan('fastleo:roles')->assertSuccessful();

    expect($admin->fresh()->roles)->toBeEmpty()
        ->and(Role::query()->where('name', 'fastleo-owner')->count())->toBe(1);
});

it('stays quiet about owners once there is one', function (): void {
    roleAdmin(role('fastleo-owner'));

    $this->artisan('fastleo:roles')->doesntExpectOutputToContain('Ни одного владельца')->assertSuccessful();
});

it('refuses somebody who cannot sign in to the admin', function (): void {
    User::factory()->create(['email' => 'visitor@example.com']);

    $this->artisan('fastleo:roles', ['--owner' => 'visitor@example.com'])
        ->expectsOutputToContain('Пользователь visitor@example.com не найден')
        ->assertFailed();
    $this->artisan('fastleo:roles', ['--owner' => 'nobody@example.com'])->assertFailed();
});

it('warns when roles are off', function (): void {
    config(['fastleo.roles.enabled' => false]);

    $this->artisan('fastleo:roles')->expectsOutputToContain('Роли выключены')->assertSuccessful();
});

it('says what is missing when the user model has no roles trait', function (): void {
    config(['auth.providers.users.model' => User::class]);

    $this->artisan('fastleo:roles')->expectsOutputToContain('нет трейта')->assertFailed();
});

it('fails with a hint when the tables of the roles package are missing', function (): void {
    foreach (['model_has_roles', 'model_has_permissions', 'role_has_permissions', 'roles', 'permissions'] as $table) {
        Schema::drop(config()->string('permission.table_names.'.$table));
    }

    $this->artisan('fastleo:roles')->expectsOutputToContain('не выполнены миграции')->assertFailed();
})->skip(fn (): bool => in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true), 'DROP TABLE в MySQL неявно закрывает транзакцию теста и оставляет базу без таблицы');

it('reports a ready setup to doctor without roles problems', function (): void {
    roleAdmin(role('fastleo-owner'));
    $sections = array_keys(Fastleo::resources()->all());

    foreach (array_diff($sections, ['users']) as $slug) {
        Permission::findOrCreate('fastleo.'.$slug.'.view', Fastleo::GUARD);
    }

    expect(roleProblems())->toBe([]);
});

it('tells doctor when roles are on but the setup is missing', function (): void {
    config(['auth.providers.users.model' => User::class]);

    expect(roleProblems())->toHaveCount(1)->and(roleProblems()[0])->toContain('нет трейта HasRoles');
});

it('tells doctor when the tables are missing', function (): void {
    Schema::drop(config()->string('permission.table_names.model_has_roles'));
    Schema::drop(config()->string('permission.table_names.model_has_permissions'));
    Schema::drop(config()->string('permission.table_names.role_has_permissions'));
    Schema::drop(config()->string('permission.table_names.roles'));
    Schema::drop(config()->string('permission.table_names.permissions'));

    expect(roleProblems()[0])->toContain('нет трейта HasRoles или не выполнены миграции');
})->skip(fn (): bool => in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true), 'DROP TABLE в MySQL неявно закрывает транзакцию теста и оставляет базу без таблицы');

it('warns about teams mode, no owner, admins without roles and direct permissions', function (): void {
    config(['permission.teams' => true]);
    $admin = roleAdmin();
    $admin->givePermissionTo(Permission::findOrCreate('fastleo.articles.view', Fastleo::GUARD));

    $problems = implode("\n", roleProblems());

    expect($problems)->toContain('режим teams')
        ->and($problems)->toContain('Нет ни одного пользователя с ролью fastleo-owner')
        ->and($problems)->toContain('Пользователей без ролей guard fastleo: 1')
        ->and($problems)->toContain('права выданы напрямую')
        ->and($problems)->toContain('У разделов нет прав');
});

it('warns when roles exist but the switch is off', function (): void {
    role('fastleo-owner');
    config(['fastleo.roles.enabled' => false]);

    expect(roleProblems())->toHaveCount(1)->and(roleProblems()[0])->toContain('fastleo.roles.enabled выключен');
});

it('says nothing about roles when they are off and unused', function (): void {
    config(['fastleo.roles.enabled' => false]);

    expect(roleProblems())->toBe([]);
});
