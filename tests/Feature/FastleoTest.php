<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Foundation\Auth\User as PlainUser;

it('builds a link for a letter on the domain of the admin when the site has a separate one', function (): void {
    config(['fastleo.domain' => 'admin.site.test', 'app.url' => 'http://site.test']);

    expect(Fastleo::mailUrl('fastleo.profile.show'))->toBe('http://admin.site.test'.route('fastleo.profile.show', absolute: false));
});

it('builds a link for a letter on the domain of the admin over https when the site address has no scheme', function (): void {
    config(['fastleo.domain' => 'admin.site.test', 'app.url' => 'site.test']);

    expect(Fastleo::mailUrl('fastleo.profile.show'))->toBe('https://admin.site.test'.route('fastleo.profile.show', absolute: false));
});

it('builds a full address of the admin from APP_URL or from the domain of the admin, never from the host of the request', function (): void {
    config(['app.url' => 'https://site.test']);
    $this->get('http://evil.example/anything');

    expect(Fastleo::adminUrl('/fastleo/leads/5'))->toBe('https://site.test/fastleo/leads/5')
        ->and(Fastleo::adminUrl('https://other.test/x'))->toBe('https://other.test/x');

    config(['fastleo.domain' => 'admin.site.test']);

    expect(Fastleo::adminUrl('/fastleo/leads/5'))->toBe('https://admin.site.test/fastleo/leads/5')
        ->and(Fastleo::adminUrl('fastleo/leads/5'))->toBe('https://admin.site.test/fastleo/leads/5')
        ->and(Fastleo::adminUrl('https://other.test/x'))->toBe('https://other.test/x');
});

it('has nobody to notify when the user model does not belong to the admin', function (): void {
    config(['auth.providers.users.model' => PlainUser::class]);

    expect(Fastleo::admins())->toBe([]);
});
