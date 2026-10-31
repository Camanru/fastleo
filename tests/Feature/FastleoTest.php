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

it('has nobody to notify when the user model does not belong to the admin', function (): void {
    config(['auth.providers.users.model' => PlainUser::class]);

    expect(Fastleo::admins())->toBe([]);
});
