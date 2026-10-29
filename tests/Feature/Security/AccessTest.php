<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Workbench\App\Models\User;

it('redirects a guest to the login form', function (): void {
    $response = $this->get(route('fastleo.dashboard'));

    $response->assertRedirect(route('fastleo.login'));
});

it('returns 401 to a guest asking for JSON', function (): void {
    $response = $this->getJson(route('fastleo.dashboard'));

    $response->assertUnauthorized();
});

it('shows the dashboard to an admin', function (): void {
    $this->actingAs(admin(['name' => 'Иван']), Fastleo::GUARD);

    $response = $this->get(route('fastleo.dashboard'));

    $response->assertOk()->assertSee('Здравствуйте, Иван!');
});

it('does not let a user signed in on the site open the admin', function (): void {
    $this->actingAs(admin(), 'web');

    $response = $this->get(route('fastleo.dashboard'));

    $response->assertRedirect(route('fastleo.login'));
});

it('logs out an admin whose access was revoked', function (): void {
    $admin = admin();
    $this->actingAs($admin, Fastleo::GUARD);
    $admin->forceFill(['fastleo_admin' => false])->save();

    $response = $this->get(route('fastleo.dashboard'));

    $response->assertRedirect(route('fastleo.login'))
        ->assertSessionHasErrors(['email' => 'У этой учётной записи нет доступа в админку.']);
    $this->assertGuest(Fastleo::GUARD);
});

it('returns 403 to a revoked admin asking for JSON', function (): void {
    $this->actingAs(User::factory()->create(), Fastleo::GUARD);

    $response = $this->getJson(route('fastleo.dashboard'));

    $response->assertForbidden();
});

it('logs out an admin whose password was changed on another device', function (): void {
    $admin = admin(['email' => 'admin@example.com']);
    $this->post(route('fastleo.login.store'), ['email' => 'admin@example.com', 'password' => 'password']);
    $this->get(route('fastleo.dashboard'))->assertOk();
    $admin->forceFill(['password' => Hash::make('new-password')])->save();
    Auth::forgetGuards(); // следующий запрос читает пользователя из базы, как на сайте

    $response = $this->get(route('fastleo.dashboard'));

    $response->assertRedirect(route('fastleo.login'));
    $this->assertGuest(Fastleo::GUARD);
});
