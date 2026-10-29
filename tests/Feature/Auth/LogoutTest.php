<?php

declare(strict_types=1);

use Fastleo\Fastleo;

it('logs the admin out, invalidates the session and rotates the csrf token', function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
    $this->startSession();
    session()->put('marker', 'value');
    $token = session()->token();

    $response = $this->post(route('fastleo.logout'));

    $response->assertRedirect(route('fastleo.login'));
    $this->assertGuest(Fastleo::GUARD);
    expect(session()->has('marker'))->toBeFalse()
        ->and(session()->token())->not->toBe($token);
});

it('does not log out on a GET request', function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);

    $response = $this->get(route('fastleo.dashboard').'/logout');

    $response->assertMethodNotAllowed();
    $this->assertAuthenticated(Fastleo::GUARD);
});

it('sends a guest posting to logout to the login form', function (): void {
    $response = $this->post(route('fastleo.logout'));

    $response->assertRedirect(route('fastleo.login'));
});
