<?php

declare(strict_types=1);

use Fastleo\Fastleo;

beforeEach(function (): void {
    // Laravel не проверяет CSRF в окружении testing — проверяем как на боевом сайте.
    $this->app['env'] = 'production';
});

it('rejects a login form without a csrf token with 419', function (): void {
    admin(['email' => 'admin@example.com']);

    $response = $this->post(route('fastleo.login.store'), ['email' => 'admin@example.com', 'password' => 'password']);

    $response->assertStatus(419);
    $this->assertGuest(Fastleo::GUARD);
});

it('rejects a logout without a csrf token with 419', function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);

    $response = $this->post(route('fastleo.logout'));

    $response->assertStatus(419);
    $this->assertAuthenticated(Fastleo::GUARD);
});

it('accepts a login form with a valid csrf token', function (): void {
    $admin = admin(['email' => 'admin@example.com']);
    $this->startSession();

    $this->post(route('fastleo.login.store'), [
        '_token' => session()->token(),
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($admin, Fastleo::GUARD);
});

it('rejects a markdown preview without a csrf token with 419', function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->postJson(route('fastleo.markdown'), ['text' => '# Заголовок'])->assertStatus(419);
});

it('rejects a reorder without a csrf token with 419', function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->postJson(route('fastleo.resources.reorder', 'topics'), ['id' => '1', 'direction' => 'up'])->assertStatus(419);
});

it('rejects profile changes and password reset forms without a csrf token with 419', function (): void {
    $admin = admin(['email' => 'anna@example.com']);

    $this->post(route('fastleo.password.email'), ['email' => 'anna@example.com'])->assertStatus(419);
    $this->post(route('fastleo.password.update'), ['token' => 'x', 'email' => 'anna@example.com', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])->assertStatus(419);

    $this->actingAs($admin, Fastleo::GUARD);

    $this->put(route('fastleo.profile.update'), ['name' => 'X', 'email' => 'x@example.com', 'current_password' => 'password'])->assertStatus(419);
    $this->put(route('fastleo.profile.password'), ['current_password' => 'password', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])->assertStatus(419);
    $this->post(route('fastleo.profile.logout-others'), ['current_password' => 'password'])->assertStatus(419);
    expect($admin->refresh()->email)->toBe('anna@example.com');
});
