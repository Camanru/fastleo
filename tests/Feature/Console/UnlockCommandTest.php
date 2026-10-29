<?php

declare(strict_types=1);

use Fastleo\Auth\LoginThrottle;
use Workbench\App\Models\User;

it('lifts the hourly block of an admin', function (): void {
    $admin = admin(['email' => 'owner@example.com']);
    $throttle = resolve(LoginThrottle::class);

    foreach (range(1, LoginThrottle::PER_ACCOUNT_HOURLY + 1) as $attempt) {
        $throttle->hit('id:'.$admin->id, '10.0.0.'.$attempt);
    }
    expect($throttle->availableIn('id:'.$admin->id, '10.0.1.1'))->toBeInt();

    $this->artisan('fastleo:unlock', ['email' => ' Owner@Example.com '])
        ->expectsOutputToContain('Блокировка входа для owner@example.com снята.')
        ->assertSuccessful();

    expect($throttle->availableIn('id:'.$admin->id, '10.0.1.1'))->toBeNull();
});

it('lifts the hourly block of the 2FA code step too', function (): void {
    $admin = admin(['email' => 'owner@example.com']);
    $throttle = resolve(LoginThrottle::class);

    foreach (range(1, LoginThrottle::PER_ACCOUNT_HOURLY + 1) as $attempt) {
        $throttle->hit('2fa:'.$admin->id, '10.0.0.'.$attempt);
    }
    expect($throttle->availableIn('2fa:'.$admin->id, '10.0.1.1'))->toBeInt();

    $this->artisan('fastleo:unlock', ['email' => 'owner@example.com'])->assertSuccessful();

    expect($throttle->availableIn('2fa:'.$admin->id, '10.0.1.1'))->toBeNull();
});

it('refuses an unknown address', function (): void {
    $this->artisan('fastleo:unlock', ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('Пользователь nobody@example.com не найден.')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});
