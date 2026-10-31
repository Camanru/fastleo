<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Recipes\LegacyHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    // Рецепт из docs/recipes.md: свой хешер, подключённый драйвером по умолчанию.
    Hash::extend('legacy', fn (): LegacyHasher => new LegacyHasher(config('hashing.bcrypt')));
    config(['hashing.driver' => 'legacy']);
});

/**
 * Пароль в старом формате md5(strrev(md5())), записанный мимо касты модели.
 */
function legacyAdmin(string $password): int
{
    $admin = admin(['email' => 'legacy@example.com']);
    DB::table('users')->where('id', $admin->id)->update(['password' => md5(strrev(md5($password)))]);

    return $admin->id;
}

it('lets an admin in with a password in the old format and replaces the hash with bcrypt', function (): void {
    $id = legacyAdmin('secret');

    login(['email' => 'legacy@example.com', 'password' => 'secret'])->assertRedirect(route('fastleo.dashboard'));

    $stored = (string) DB::table('users')->where('id', $id)->value('password');

    expect(auth(Fastleo::GUARD)->check())->toBeTrue()
        ->and($stored)->toStartWith('$2y$')
        ->and(Hash::driver('bcrypt')->check('secret', $stored))->toBeTrue();
});

it('refuses a wrong password in the old format and keeps the hash', function (): void {
    $id = legacyAdmin('secret');

    login(['email' => 'legacy@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');

    expect(auth(Fastleo::GUARD)->check())->toBeFalse()
        ->and(DB::table('users')->where('id', $id)->value('password'))->toBe(md5(strrev(md5('secret'))));
});

it('keeps accepting ordinary bcrypt passwords', function (): void {
    admin(['email' => 'new@example.com']);

    login(['email' => 'new@example.com', 'password' => 'password'])->assertRedirect(route('fastleo.dashboard'));

    expect(auth(Fastleo::GUARD)->check())->toBeTrue();
});
