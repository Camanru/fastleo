<?php

declare(strict_types=1);

use Fastleo\Fastleo;

it('sends security headers on admin pages', function (string $page): void {
    $this->actingAs(admin(), Fastleo::GUARD);
    if ($page === 'fastleo.login') {
        Fastleo::guard()->logout();
    }

    $response = $this->get(route($page));

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
})->with(['fastleo.login', 'fastleo.dashboard']);

it('sends security headers on the redirect of a guest and on a 401 answer', function (): void {
    $redirect = $this->get(route('fastleo.dashboard'));

    $redirect->assertRedirect(route('fastleo.login'))
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect($redirect->headers->get('Cache-Control'))->toContain('no-store');

    $unauthorized = $this->getJson(route('fastleo.dashboard'));

    $unauthorized->assertUnauthorized()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect($unauthorized->headers->get('Cache-Control'))->toContain('no-store');
});
