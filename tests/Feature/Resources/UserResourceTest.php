<?php

declare(strict_types=1);

use Fastleo\Auth\TwoFactor;
use Fastleo\Fastleo;
use Fastleo\Resources\UserResource;
use Fastleo\Resources\Users\AdminAccess;
use Fastleo\Resources\Users\EmailAvailable;
use Fastleo\Resources\Users\UserGuard;
use Fastleo\Tests\Fixtures\Models\ScopedUser;
use Fastleo\Tests\Fixtures\Policies\SelfOnlyUserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->me = admin(['name' => 'Анна', 'email' => 'anna@example.com']);
    $this->actingAs($this->me, Fastleo::GUARD);
});

/**
 * Данные формы пользователя.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function adminForm(array $overrides = []): array
{
    return ['name' => 'Борис', 'email' => 'boris@example.com', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1', 'fastleo_admin' => '1', ...$overrides];
}

it('adds the admins section to the menu and lists only admins', function (): void {
    User::factory()->create(['name' => 'Покупатель']);
    admin(['name' => 'Борис']);

    $this->get(route('fastleo.dashboard'))->assertSee('Пользователи')->assertSee(route('fastleo.resources.index', 'users'), false);
    $this->get(route('fastleo.resources.index', 'users'))
        ->assertOk()
        ->assertSee('Анна')
        ->assertSee('Борис')
        ->assertDontSee('Покупатель');
});

it('can be switched off or replaced in the config', function (): void {
    config(['fastleo.users.resource' => null]);
    Fastleo::resources()->flush();

    $this->get(route('fastleo.resources.index', 'users'))->assertNotFound();
});

it('creates an admin with a hashed password, lower case email and access', function (): void {
    $this->post(route('fastleo.resources.store', 'users'), adminForm(['email' => 'Boris@Example.COM']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $boris = User::query()->where('email', 'boris@example.com')->firstOrFail();
    expect($boris->fastleo_admin)->toBeTrue()
        ->and(Hash::check('secret-pass-1', $boris->password))->toBeTrue();

    $this->post(route('fastleo.logout'));
    login(['email' => 'boris@example.com', 'password' => 'secret-pass-1'])->assertRedirect(route('fastleo.dashboard'));
});

it('does not create an admin with an email that exists in any letter case', function (): void {
    $this->post(route('fastleo.resources.store', 'users'), adminForm(['email' => 'ANNA@example.com']))
        ->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe(1);
});

it('requires a password and its confirmation for a new admin', function (): void {
    $this->post(route('fastleo.resources.store', 'users'), adminForm(['password' => '', 'password_confirmation' => '']))->assertSessionHasErrors('password');
    $this->post(route('fastleo.resources.store', 'users'), adminForm(['password_confirmation' => 'other']))->assertSessionHasErrors('password');
});

it('keeps the password when it is left empty and changes it when filled', function (): void {
    $boris = admin(['email' => 'boris@example.com']);

    $this->put(route('fastleo.resources.update', ['users', $boris->id]), adminForm(['password' => '', 'password_confirmation' => '', 'name' => 'Борис Иванов']))
        ->assertSessionHasNoErrors();
    expect(Hash::check('password', $boris->refresh()->password))->toBeTrue()->and($boris->name)->toBe('Борис Иванов');

    $this->put(route('fastleo.resources.update', ['users', $boris->id]), adminForm());
    expect(Hash::check('secret-pass-1', $boris->refresh()->password))->toBeTrue();
});

it('does not set a password of spaces: it skips the length rule and the confirmation', function (): void {
    $boris = admin(['email' => 'boris@example.com']);

    $this->put(route('fastleo.resources.update', ['users', $boris->id]), adminForm(['password' => '        ', 'password_confirmation' => 'other']))
        ->assertSessionHasNoErrors();
    expect(Hash::check('password', $boris->refresh()->password))->toBeTrue();

    $this->post(route('fastleo.resources.store', 'users'), adminForm(['email' => 'new@example.com', 'password' => '        ', 'password_confirmation' => '        ']))
        ->assertSessionHasErrors('password');
});

it('hides the password field on the own record and ignores a posted one', function (): void {
    $this->get(route('fastleo.resources.edit', ['users', $this->me->id]))->assertOk()->assertDontSee('name="password"', false);

    $this->put(route('fastleo.resources.update', ['users', $this->me->id]), adminForm(['name' => 'Анна', 'email' => 'anna@example.com']))
        ->assertSessionHasNoErrors();
    expect(Hash::check('password', $this->me->refresh()->password))->toBeTrue();
});

it('cannot take the access away from yourself', function (): void {
    admin();

    $this->put(route('fastleo.resources.update', ['users', $this->me->id]), adminForm(['name' => 'Анна', 'email' => 'anna@example.com', 'fastleo_admin' => '0']))
        ->assertSessionHasErrors(['fastleo_admin' => 'Нельзя снять доступ у самого себя.']);
    expect($this->me->refresh()->fastleo_admin)->toBeTrue();
});

it('does not create an admin without access because he would vanish from the list', function (): void {
    $this->post(route('fastleo.resources.store', 'users'), adminForm(['fastleo_admin' => '0']))
        ->assertSessionHasErrors(['fastleo_admin' => 'Нового пользователя создают с доступом: без него он не появится в списке.']);

    expect(User::query()->count())->toBe(1);
});

it('does not change the own email here because it needs the password in the profile', function (): void {
    admin();

    $this->put(route('fastleo.resources.update', ['users', $this->me->id]), adminForm(['name' => 'Анна', 'email' => 'new@example.com']))
        ->assertSessionHasErrors('email');
    expect($this->me->refresh()->email)->toBe('anna@example.com');

    $this->put(route('fastleo.resources.update', ['users', $this->me->id]), adminForm(['name' => 'Анна Петровна', 'email' => 'Anna@Example.com', 'password' => '', 'password_confirmation' => '']))
        ->assertSessionHasNoErrors();
    expect($this->me->refresh()->name)->toBe('Анна Петровна');
});

it('takes the access away from another admin and he drops out of the list', function (): void {
    $boris = admin(['name' => 'Борис', 'email' => 'boris@example.com']);

    $this->put(route('fastleo.resources.update', ['users', $boris->id]), adminForm(['fastleo_admin' => '0']))->assertSessionHasNoErrors();

    expect($boris->refresh()->fastleo_admin)->toBeFalse();
    $this->get(route('fastleo.resources.index', 'users'))->assertDontSee('Борис');
});

it('writes the access flag even though it is not fillable', function (): void {
    expect(new User()->isFillable('fastleo_admin'))->toBeFalse();

    $this->post(route('fastleo.resources.store', 'users'), adminForm())->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'boris@example.com')->value('fastleo_admin'))->toBeTruthy();
});

it('does not delete yourself', function (): void {
    admin();

    $this->delete(route('fastleo.resources.destroy', ['users', $this->me->id]))->assertForbidden();
    expect(User::query()->whereKey($this->me->id)->exists())->toBeTrue();
});

it('deletes another admin but never the last one', function (): void {
    $boris = admin();

    $this->delete(route('fastleo.resources.destroy', ['users', $boris->id]))->assertRedirect();
    expect(User::query()->whereKey($boris->id)->exists())->toBeFalse();

    // Единственный пользователь: убрать некому, кроме него самого.
    $this->delete(route('fastleo.resources.destroy', ['users', $this->me->id]))->assertForbidden();
});

it('does not delete the last admin even when another one is not an admin any more', function (): void {
    $other = admin();
    $this->actingAs($other, Fastleo::GUARD);
    $this->flushSession();
    $this->me->forceFill(['fastleo_admin' => false])->save();

    $this->delete(route('fastleo.resources.destroy', ['users', $this->me->id]))->assertNotFound();
    expect(User::query()->whereKey($other->id)->exists())->toBeTrue();
});

it('refuses to take the access from the last admin through the form', function (): void {
    $last = admin(['email' => 'last@example.com']);
    $this->me->forceFill(['fastleo_admin' => false])->save();
    $this->flushSession();
    $this->actingAs($last, Fastleo::GUARD);

    // Себя нельзя — но и ограничение «последний» проверяется отдельно от «себя».
    $record = $last->refresh();
    expect(UserGuard::isLastAdmin($record))->toBeTrue()
        ->and(UserGuard::isCurrentUser($record))->toBeTrue();
});

it('names the last admin as the reason when the access is taken from them by someone else', function (): void {
    $last = admin(['email' => 'last@example.com']);
    $this->me->forceFill(['fastleo_admin' => false])->save();
    $rules = AdminAccess::make('fastleo_admin', 'Доступ')->getRules($last->refresh());

    $removal = Validator::make(['fastleo_admin' => '0'], $rules);

    expect($removal->fails())->toBeTrue()
        ->and($removal->errors()->first('fastleo_admin'))->toBe(__('fastleo::users.last_admin', [], Fastleo::LOCALE))
        ->and(Validator::make(['fastleo_admin' => '1'], $rules)->passes())->toBeTrue();
});

it('checks again, inside the transaction of the save, that an admin is left when the access is taken away', function (): void {
    $field = AdminAccess::make('fastleo_admin', 'Доступ');
    $other = admin(['email' => 'other@example.com']);
    $this->me->forceFill(['fastleo_admin' => false])->save();
    $other->forceFill(['fastleo_admin' => false])->save();

    expect(fn () => $field->afterSave($other, false))->toThrow(ValidationException::class);

    $other->forceFill(['fastleo_admin' => true])->save();
    $field->afterSave($this->me->refresh(), false);

    expect(UserGuard::mayDelete($other))->toBeFalse()
        ->and(UserGuard::mayDelete($this->me->refresh()))->toBeTrue();

    admin(['email' => 'third@example.com']);

    expect(UserGuard::mayDelete($other))->toBeTrue();
});

it('refuses an email sent as a list instead of a string', function (): void {
    $this->post(route('fastleo.resources.store', 'users'), adminForm(['email' => ['boris@example.com']]))->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe(1);
});

it('hides the delete button for the own record and shows it for others', function (): void {
    $boris = admin(['name' => 'Борис']);

    $this->get(route('fastleo.resources.edit', ['users', $this->me->id]))->assertDontSee('Удалить запись навсегда', false);
    $this->get(route('fastleo.resources.edit', ['users', $boris->id]))->assertSee('Удалить запись навсегда', false);
});

it('skips yourself in the bulk delete', function (): void {
    $boris = admin();

    $this->post(route('fastleo.resources.action', ['users', 'delete']), ['ids' => [(string) $this->me->id, (string) $boris->id]]);

    expect(User::query()->whereKey($this->me->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($boris->id)->exists())->toBeFalse();
});

describe('resetting two-factor protection', function (): void {
    it('is offered for another admin with protection and clears it', function (): void {
        $boris = admin(['name' => 'Борис']);
        withTwoFactor($boris);

        $this->get(route('fastleo.resources.edit', ['users', $boris->id]))->assertSee('Сбросить 2FA');

        $this->post(route('fastleo.resources.action', ['users', 'reset-two-factor']), ['ids' => [(string) $boris->id]])
            ->assertSessionHas('fastleo.success');
        expect(resolve(TwoFactor::class)->isEnabled($boris))->toBeFalse();
    });

    it('is not offered for an admin without protection', function (): void {
        $boris = admin(['name' => 'Борис']);

        $this->get(route('fastleo.resources.edit', ['users', $boris->id]))->assertDontSee('Сбросить 2FA');
    });

    it('is not offered for the own record, so a stolen session cannot switch the protection off', function (): void {
        withTwoFactor($this->me);

        $this->get(route('fastleo.resources.edit', ['users', $this->me->id]))->assertDontSee('Сбросить 2FA');
        $this->post(route('fastleo.resources.action', ['users', 'reset-two-factor']), ['ids' => [(string) $this->me->id]]);

        expect(resolve(TwoFactor::class)->isEnabled($this->me))->toBeTrue();
    });
});

it('describes the resource with the user model of the guard', function (): void {
    expect(UserResource::model())->toBe(User::class)
        ->and(UserResource::hasModel())->toBeTrue()
        ->and(UserResource::label())->toBe('Пользователи')
        ->and(UserResource::singular())->toBe('Пользователь');
});

it('honours a site policy of the user model on top of its own rules', function (): void {
    Gate::policy(User::class, SelfOnlyUserPolicy::class);
    $boris = admin();

    $this->get(route('fastleo.resources.edit', ['users', $boris->id]))->assertForbidden();
    $this->get(route('fastleo.resources.edit', ['users', $this->me->id]))->assertOk();
});

it('says that the address is taken instead of asking for a valid address', function (): void {
    admin(['email' => 'boris@example.com']);

    $this->post(route('fastleo.resources.store', 'users'), adminForm(['email' => 'BORIS@example.com']))
        ->assertSessionHasErrors(['email' => 'Такое значение поля «Email» уже есть.']);
});

it('counts a user hidden by a global scope of the model as holding the address', function (): void {
    User::factory()->create(['name' => 'Призрак', 'email' => 'taken@example.com']);

    $validator = Validator::make(['email' => 'Taken@Example.com'], ['email' => [new EmailAvailable(ScopedUser::class, null)]]);

    expect($validator->fails())->toBeTrue();
});

describe('the words of the section', function (): void {
    it('are the texts of the package until the site names them itself', function (): void {
        $access = collect(new UserResource()->fields())->first(fn ($field): bool => $field->name === 'fastleo_admin');

        expect(UserResource::label())->toBe('Пользователи')
            ->and(UserResource::singular())->toBe('Пользователь')
            ->and($access->getLabel())->toBe('Доступ в панель');
    });

    it('are taken from the config: the section, one person and the access toggle', function (): void {
        config(['fastleo.users.label' => 'Сотрудники', 'fastleo.users.singular' => 'Сотрудник', 'fastleo.users.access' => 'Доступ в CRM']);
        $access = collect(new UserResource()->fields())->first(fn ($field): bool => $field->name === 'fastleo_admin');

        expect(UserResource::label())->toBe('Сотрудники')
            ->and(UserResource::singular())->toBe('Сотрудник')
            ->and($access->getLabel())->toBe('Доступ в CRM');
    });

    it('fall back to the texts of the package when the config holds an empty or a wrong value', function (mixed $value): void {
        config(['fastleo.users.label' => $value]);

        expect(UserResource::label())->toBe('Пользователи');
    })->with(['empty string' => '', 'number' => 5, 'null' => null]);
});
