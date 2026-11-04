<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Imports\Import;
use Fastleo\Resources\Roles\RoleName;
use Fastleo\Resources\Users\AdminAccess;
use Fastleo\Resources\Users\UserGuard;
use Fastleo\Support\OperationCancelled;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Workbench\App\Models\Category;
use Workbench\App\Models\User;

beforeEach(function (): void {
    useRoles();
    $this->owner = role('fastleo-owner');
});

it('refuses to delete the only owner before the roles of the record are taken away', function (): void {
    $only = roleAdmin($this->owner);

    expect(fn () => Fastleo::resources()->find('users')?->beforeDelete($only))->toThrow(OperationCancelled::class, 'последнего владельца');
});

it('deletes an owner while another owner with access remains', function (): void {
    $first = roleAdmin($this->owner);
    roleAdmin($this->owner);

    Fastleo::resources()->find('users')?->beforeDelete($first);

    expect(UserGuard::mayDelete($first))->toBeTrue();
});

it('does not count an owner who cannot sign in as the one that remains when deleting', function (): void {
    $active = roleAdmin($this->owner);
    roleAdmin($this->owner, ['fastleo_admin' => false]);

    expect(UserGuard::mayDelete($active))->toBeFalse();
});

it('lets an administrator who is not an owner go, and any user while roles are off', function (): void {
    roleAdmin($this->owner);

    expect(UserGuard::mayDelete(roleAdmin(role('editor', ['articles.view']))))->toBeTrue();

    $only = roleAdmin($this->owner);
    config(['fastleo.roles.enabled' => false]);

    expect(UserGuard::mayDelete($only))->toBeTrue();
});

it('lets a site without the owner role delete users', function (): void {
    Role::query()->where('name', 'fastleo-owner')->delete();

    expect(UserGuard::mayDelete(roleAdmin()))->toBeTrue();
});

it('says why the record stays when the section refuses to delete it', function (): void {
    useFixtureResources('DeleteHook');
    $keep = Category::factory()->create(['code' => 'keep']);
    $free = Category::factory()->create(['code' => 'free']);
    $this->actingAs(roleAdmin($this->owner), Fastleo::GUARD);

    $this->delete(route('fastleo.resources.destroy', ['categories', $keep->id]))->assertSessionHas('fastleo.error', 'Категория нужна сайту.');
    $this->post(route('fastleo.resources.action', ['categories', 'delete']), ['ids' => [$keep->id, $free->id]])->assertSessionHas('fastleo.success');

    expect(Category::query()->whereKey($keep->id)->exists())->toBeTrue()
        ->and(Category::query()->whereKey($free->id)->exists())->toBeFalse();
});

it('refuses to switch off the access of the only owner and rolls the form back', function (): void {
    $only = roleAdmin($this->owner, ['fastleo_admin' => false]);

    expect(fn () => new AdminAccess('fastleo_admin')->afterSave($only, '0'))->toThrow(ValidationException::class);
});

it('lets the access of an owner go while another owner can sign in', function (): void {
    $other = roleAdmin($this->owner, ['fastleo_admin' => false]);
    roleAdmin($this->owner);

    new AdminAccess('fastleo_admin')->afterSave($other, '0');
    new AdminAccess('fastleo_admin')->afterSave($other, '1');
    new AdminAccess('fastleo_admin')->afterSave(roleAdmin(role('editor')), '0');

    expect($other->fresh()->fastleo_admin)->toBeFalse();
});

it('leaves a user model without the roles trait alone', function (): void {
    $plain = User::factory()->create(['fastleo_admin' => true]);

    new AdminAccess('fastleo_admin')->afterSave($plain, '0');

    expect(UserGuard::mayDelete($plain))->toBeTrue();
});

it('does not look at the owners while roles are off', function (): void {
    config(['fastleo.roles.enabled' => false]);
    admin();

    new AdminAccess('fastleo_admin')->afterSave(roleAdmin($this->owner, ['fastleo_admin' => false]), '0');

    expect(true)->toBeTrue();
});

it('puts a new role under the fastleo guard whatever guard it came with', function (): void {
    $role = new Role(['name' => 'Чужая', 'guard_name' => 'web']);

    RoleName::make('name')->beforeSave($role);

    expect($role->guard_name)->toBe(Fastleo::GUARD);
});

it('reads the rights of the uploader anew when an import starts', function (): void {
    Storage::fake('local');
    useFixtureResources('Imports');
    $user = roleAdmin(role('importer', ['categories.view', 'categories.create']));
    $this->actingAs($user, Fastleo::GUARD);

    expect(Fastleo::guard()->user()?->checkPermissionTo('fastleo.categories.create', Fastleo::GUARD))->toBeTrue();

    // Права отобраны мимо моделей spatie (как другой процесс): кеш этого процесса о них ещё не знает.
    DB::table(config()->string('permission.table_names.role_has_permissions'))->delete();

    $this->post(route('fastleo.resources.import', 'categories'), [
        'file' => UploadedFile::fake()->createWithContent('categories.csv', "Код;Название\nnew;Новая\n"),
    ]);

    expect(Import::query()->latest('id')->firstOrFail()->only('created', 'skipped'))->toBe(['created' => 0, 'skipped' => 1])
        ->and(Category::query()->where('code', 'new')->exists())->toBeFalse();
});
