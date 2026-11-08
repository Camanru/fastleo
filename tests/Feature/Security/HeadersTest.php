<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

it('does not let the browser remember the redirect of a guest from a thumbnail to the login page', function (): void {
    // Иначе после входа миниатюры не грузились бы, пока браузер помнит переход на форму входа.
    $guest = $this->get(route('fastleo.files.thumb', ['path' => 'uploads/a.jpg']));

    $guest->assertRedirect(route('fastleo.login'));
    expect($guest->headers->get('Cache-Control'))->toContain('no-store')->not->toContain('max-age=300');
});

it('lets the browser remember the redirect to a thumbnail of the file manager, but nothing else', function (): void {
    $disk = Storage::fake('public');
    $disk->put('uploads/a.jpg', UploadedFile::fake()->image('a.jpg', 800, 600)->getContent());
    $this->actingAs(admin(), Fastleo::GUARD);

    $thumb = $this->get(route('fastleo.files.thumb', ['path' => 'uploads/a.jpg']));
    $missing = $this->get(route('fastleo.files.thumb', ['path' => 'uploads/none.jpg']));

    expect($thumb->isRedirection())->toBeTrue()
        ->and($thumb->headers->get('Cache-Control'))->toContain('max-age=300')->toContain('private')->not->toContain('no-store')
        ->and($missing->headers->get('Cache-Control'))->toContain('no-store');
    expect($this->get(route('fastleo.files.index'))->headers->get('Cache-Control'))->toContain('no-store');
});
