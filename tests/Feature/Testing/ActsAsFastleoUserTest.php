<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Testing\ActsAsFastleoUser;

uses(ActsAsFastleoUser::class);

it('changes the signed in user in the middle of a test, which a second actingAs() does not', function (): void {
    $first = admin(['email' => 'first@example.com']);
    $second = admin(['email' => 'second@example.com']);

    $this->actingAsFastleo($first)->get(route('fastleo.profile.show'))->assertOk();
    expect(Fastleo::guard()->user()?->getAuthIdentifier())->toBe($first->getAuthIdentifier());

    $this->actingAsFastleo($second)->get(route('fastleo.profile.show'))->assertOk();
    expect(Fastleo::guard()->user()?->getAuthIdentifier())->toBe($second->getAuthIdentifier());
});

it('returns the test, so that a request can follow in the same chain', function (): void {
    expect($this->actingAsFastleo(admin()))->toBe($this);
});
