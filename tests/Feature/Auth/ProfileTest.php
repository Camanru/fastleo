<?php

declare(strict_types=1);

use Fastleo\Auth\LoginRecord;
use Fastleo\Fastleo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->admin = admin(['name' => 'Анна', 'email' => 'anna@example.com']);
    $this->actingAs($this->admin, Fastleo::GUARD);
});

it('shows the profile and links to it from the header', function (): void {
    $this->get(route('fastleo.profile.show'))
        ->assertOk()
        ->assertSee('Анна')
        ->assertSee('anna@example.com')
        ->assertSee('Где вы вошли');

    $this->get(route('fastleo.dashboard'))->assertSee(route('fastleo.profile.show'), false);
});

it('redirects a guest from the profile to the login form', function (): void {
    $this->app['auth']->guard(Fastleo::GUARD)->logout();

    $this->get(route('fastleo.profile.show'))->assertRedirect(route('fastleo.login'));
});

it('changes the name without asking for a password', function (): void {
    $this->put(route('fastleo.profile.update'), ['name' => 'Анна Петрова', 'email' => 'anna@example.com'])
        ->assertRedirect(route('fastleo.profile.show'))
        ->assertSessionHas('fastleo.success');

    expect($this->admin->refresh()->name)->toBe('Анна Петрова');
});

it('requires the current password to change the email and stores it in lower case', function (): void {
    $this->put(route('fastleo.profile.update'), ['name' => 'Анна', 'email' => 'New@Example.com'])
        ->assertSessionHasErrorsIn('profile', 'current_password');
    expect($this->admin->refresh()->email)->toBe('anna@example.com');

    $this->put(route('fastleo.profile.update'), ['name' => 'Анна', 'email' => 'New@Example.com', 'current_password' => 'wrong'])
        ->assertSessionHasErrorsIn('profile', 'current_password');

    $this->put(route('fastleo.profile.update'), ['name' => 'Анна', 'email' => 'New@Example.com', 'current_password' => 'password'])
        ->assertSessionHasNoErrors();
    expect($this->admin->refresh()->email)->toBe('new@example.com');
});

it('rejects an email taken by another user stored with capital letters', function (): void {
    User::factory()->create(['email' => 'Taken@Example.com']);

    $this->put(route('fastleo.profile.update'), ['name' => 'Анна', 'email' => 'taken@example.com', 'current_password' => 'password'])
        ->assertSessionHasErrorsIn('profile', 'email');
    expect($this->admin->refresh()->email)->toBe('anna@example.com');
})->skip(fn (): bool => in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true), 'MySQL не различает регистр: правило сработало бы и без lower()');

it('rejects an email taken by another user', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->put(route('fastleo.profile.update'), ['name' => 'Анна', 'email' => 'taken@example.com', 'current_password' => 'password'])
        ->assertSessionHasErrorsIn('profile', 'email');
    expect($this->admin->refresh()->email)->toBe('anna@example.com');
});

it('does not touch the admin flag or other columns through the profile form', function (): void {
    $this->put(route('fastleo.profile.update'), ['name' => 'Анна', 'email' => 'anna@example.com', 'fastleo_admin' => '0', 'password' => 'hacked-password']);

    $this->admin->refresh();
    expect($this->admin->fastleo_admin)->toBeTrue()
        ->and(Hash::check('password', $this->admin->password))->toBeTrue();
});

it('changes the password after checking the current one', function (): void {
    $this->put(route('fastleo.profile.password'), ['current_password' => 'wrong', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
        ->assertSessionHasErrorsIn('password', 'current_password');

    $this->put(route('fastleo.profile.password'), ['current_password' => 'password', 'password' => 'new-secret-123', 'password_confirmation' => 'other'])
        ->assertSessionHasErrorsIn('password', 'password');

    $this->put(route('fastleo.profile.password'), ['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertSessionHasErrorsIn('password', 'password');

    $this->put(route('fastleo.profile.password'), ['current_password' => 'password', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
        ->assertRedirect(route('fastleo.profile.show'))
        ->assertSessionHas('fastleo.success');

    expect(Hash::check('new-secret-123', $this->admin->refresh()->password))->toBeTrue();
});

it('keeps the current device signed in after a password change', function (): void {
    $this->put(route('fastleo.profile.password'), ['current_password' => 'password', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123']);

    $this->get(route('fastleo.profile.show'))->assertOk();
});

it('ends other sessions when the password changes', function (): void {
    // Устройство A вошло и сохранило в сеансе хеш пароля.
    $this->flushSession();
    $this->post(route('fastleo.login.store'), ['email' => 'anna@example.com', 'password' => 'password']);
    $this->get(route('fastleo.dashboard'));
    $sessionOfDeviceA = session()->all();
    expect($sessionOfDeviceA)->toHaveKey('password_hash_fastleo');

    // Устройство B меняет пароль.
    $this->flushSession();
    $this->actingAs($this->admin->refresh(), Fastleo::GUARD)
        ->put(route('fastleo.profile.password'), ['current_password' => 'password', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123']);

    // Устройство A с прежним хешем больше не пускают.
    $this->app['auth']->guard(Fastleo::GUARD)->logout();
    $this->flushSession();
    $this->withSession($sessionOfDeviceA)
        ->actingAs($this->admin->refresh(), Fastleo::GUARD)
        ->get(route('fastleo.profile.show'))
        ->assertRedirect(route('fastleo.login'));
});

it('logs out other devices after checking the password and forgets their records', function (): void {
    $this->app['auth']->guard(Fastleo::GUARD)->logout();
    $this->flushSession();
    $this->withHeader('User-Agent', 'Phone')->post(route('fastleo.login.store'), ['email' => 'anna@example.com', 'password' => 'password']);
    $this->withHeader('User-Agent', 'Laptop')->post(route('fastleo.login.store'), ['email' => 'anna@example.com', 'password' => 'password']);
    expect(LoginRecord::query()->count())->toBe(2);

    $this->withHeader('User-Agent', 'Laptop')
        ->post(route('fastleo.profile.logout-others'), ['current_password' => 'wrong'])
        ->assertSessionHasErrorsIn('sessions', 'current_password');
    expect(LoginRecord::query()->count())->toBe(2);

    $hashBefore = $this->admin->refresh()->password;
    $this->withHeader('User-Agent', 'Laptop')
        ->post(route('fastleo.profile.logout-others'), ['current_password' => 'password'])
        ->assertRedirect(route('fastleo.profile.show'));

    expect(LoginRecord::query()->count())->toBe(1)
        ->and(LoginRecord::query()->value('user_agent'))->toBe('Laptop')
        ->and($this->admin->refresh()->password)->not->toBe($hashBefore);
});

it('lists the devices and marks the current one', function (): void {
    $this->app['auth']->guard(Fastleo::GUARD)->logout();
    $this->flushSession();
    $this->withHeader('User-Agent', 'Phone')->post(route('fastleo.login.store'), ['email' => 'anna@example.com', 'password' => 'password']);

    $this->withHeader('User-Agent', 'Phone')->get(route('fastleo.profile.show'))
        ->assertSee('Phone')
        ->assertSee('Это устройство');
    $this->withHeader('User-Agent', 'Tablet')->get(route('fastleo.profile.show'))
        ->assertSee('Phone')
        ->assertDontSee('Это устройство');
});
