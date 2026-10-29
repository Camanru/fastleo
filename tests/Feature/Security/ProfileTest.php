<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Support\Facades\Hash;
use Workbench\App\Models\User;

it('limits guessing of the current password from a stolen session', function (): void {
    $admin = admin(['email' => 'anna@example.com']);
    $this->actingAs($admin, Fastleo::GUARD);

    foreach (range(1, 5) as $_) {
        $this->put(route('fastleo.profile.password'), ['current_password' => 'guess', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
            ->assertSessionHasErrorsIn('password', 'current_password');
    }

    $this->put(route('fastleo.profile.password'), ['current_password' => 'password', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
        ->assertTooManyRequests();
    expect(Hash::check('password', $admin->refresh()->password))->toBeTrue();
});

it('counts profile attempts per admin, not per address shared by many', function (): void {
    $first = admin();
    $second = admin();

    $this->actingAs($first, Fastleo::GUARD);
    foreach (range(1, 6) as $_) {
        $this->post(route('fastleo.profile.logout-others'), ['current_password' => 'guess']);
    }

    $this->flushSession();
    $this->actingAs($second, Fastleo::GUARD)
        ->post(route('fastleo.profile.logout-others'), ['current_password' => 'guess'])
        ->assertSessionHasErrorsIn('sessions', 'current_password');
});

it('keeps profile pages away from guests and non-admin users', function (): void {
    $this->get(route('fastleo.profile.show'))->assertRedirect(route('fastleo.login'));
    $this->put(route('fastleo.profile.update'), ['name' => 'X', 'email' => 'x@example.com'])->assertRedirect(route('fastleo.login'));

    $this->actingAs(User::factory()->create(), Fastleo::GUARD)
        ->get(route('fastleo.profile.show'))
        ->assertRedirect(route('fastleo.login'));
});

it('does not change data with GET requests', function (): void {
    $admin = admin(['name' => 'Анна']);
    $this->actingAs($admin, Fastleo::GUARD);

    $this->get(route('fastleo.profile.show', ['name' => 'Hacked', 'email' => 'hacked@example.com']));
    $this->get(route('fastleo.profile.logout-others'))->assertClientError();

    expect($admin->refresh()->name)->toBe('Анна');
});

it('does not reveal an existing account through the reset form message or response code', function (): void {
    admin(['email' => 'anna@example.com']);

    $known = $this->post(route('fastleo.password.email'), ['email' => 'anna@example.com']);
    $unknown = $this->post(route('fastleo.password.email'), ['email' => 'ghost@example.com']);

    expect($known->status())->toBe($unknown->status())
        ->and($known->getSession()->get('status'))->toBe($unknown->getSession()->get('status'));
});
