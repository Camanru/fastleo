<?php

declare(strict_types=1);

use Fastleo\Auth\LoginThrottle;
use Fastleo\Fastleo;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\User;

/**
 * Неудачные попытки входа с указанного IP.
 */
function failLogins(int $times, string $email, string $ip = '10.0.0.1'): void
{
    for ($i = 0; $i < $times; $i++) {
        test()->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('fastleo.login.store'), ['email' => $email, 'password' => 'wrong-password']);
    }
}

it('blocks the sixth attempt for the same account and IP even with the right password', function (): void {
    admin(['email' => 'admin@example.com']);
    failLogins(5, 'admin@example.com');

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->post(route('fastleo.login.store'), ['email' => 'admin@example.com', 'password' => 'password']);

    $response->assertSessionHasErrors(['email' => 'Слишком много попыток входа. Повторите через 1 мин.']);
    $this->assertGuest(Fastleo::GUARD);
});

it('lets the account in from another IP after five failures', function (): void {
    $admin = admin(['email' => 'admin@example.com']);
    failLogins(5, 'admin@example.com');

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->post(route('fastleo.login.store'), ['email' => 'admin@example.com', 'password' => 'password']);

    $this->assertAuthenticatedAs($admin, Fastleo::GUARD);
});

it('blocks an IP after 20 attempts across different emails', function (): void {
    $admin = admin(['email' => 'admin@example.com']);
    foreach (range(1, 20) as $number) {
        failLogins(1, "guess{$number}@example.com");
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->post(route('fastleo.login.store'), ['email' => 'admin@example.com', 'password' => 'password']);

    $response->assertSessionHasErrors('email');
    $this->assertGuest(Fastleo::GUARD);
    expect($admin->exists)->toBeTrue();
});

it('blocks an account for an hour after 50 attempts from many IPs', function (): void {
    admin(['email' => 'admin@example.com']);
    foreach (range(1, 50) as $number) {
        failLogins(1, 'admin@example.com', "10.0.1.{$number}");
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.1'])
        ->post(route('fastleo.login.store'), ['email' => 'admin@example.com', 'password' => 'password']);

    $response->assertSessionHasErrors(['email' => 'Слишком много попыток входа. Повторите через 60 мин.']);
    $this->assertGuest(Fastleo::GUARD);
});

it('resets the counters after a successful login', function (): void {
    $admin = admin(['email' => 'admin@example.com']);
    failLogins(4, 'admin@example.com');
    $this->post(route('fastleo.login.store'), ['email' => 'admin@example.com', 'password' => 'password']);
    Fastleo::guard()->logout();
    failLogins(4, 'admin@example.com');

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->post(route('fastleo.login.store'), ['email' => 'admin@example.com', 'password' => 'password']);

    $this->assertAuthenticatedAs($admin, Fastleo::GUARD);
});

it('does not give the IP a fresh limit after a successful login', function (): void {
    admin(['email' => 'admin@example.com']);

    foreach (range(1, 19) as $number) {
        failLogins(1, "guess{$number}@example.com");
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->post(route('fastleo.login.store'), ['email' => 'admin@example.com', 'password' => 'password']);
    $this->assertAuthenticatedAs(User::query()->firstOrFail(), Fastleo::GUARD);
    Fastleo::guard()->logout();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->post(route('fastleo.login.store'), ['email' => 'another@example.com', 'password' => 'x'])
        ->assertSessionHasErrors(['email' => 'Слишком много попыток входа. Повторите через 1 мин.']);
});

it('refuses the attempts that a parallel burst slipped past the check', function (): void {
    $throttle = resolve(LoginThrottle::class);

    $results = array_map(fn (): ?int => $throttle->hit('id:1', '10.0.0.9'), range(1, LoginThrottle::PER_ACCOUNT_AND_IP + 1));

    expect(array_slice($results, 0, LoginThrottle::PER_ACCOUNT_AND_IP))->each->toBeNull()
        ->and($results[LoginThrottle::PER_ACCOUNT_AND_IP])->toBeInt()->toBeGreaterThan(0);
});

it('does not let a look-alike email log in or bypass the account limit', function (): void {
    admin(['email' => 'admin@example.com']);
    failLogins(5, 'admin@example.com');

    // Буква «а» — кириллическая: в базе с нестрогим сравнением такой адрес нашёл бы того же пользователя.
    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->post(route('fastleo.login.store'), ['email' => 'аdmin@example.com', 'password' => 'password']);

    $response->assertSessionHasErrors(['email' => 'Неверный email или пароль.']);
    $this->assertGuest(Fastleo::GUARD);
});

it('rejects an accented email that the MySQL collation treats as the admin email', function (): void {
    admin(['email' => 'admin@example.com']);

    $response = $this->post(route('fastleo.login.store'), ['email' => 'ádmin@example.com', 'password' => 'password']);

    $response->assertSessionHasErrors(['email' => 'Неверный email или пароль.']);
    $this->assertGuest(Fastleo::GUARD);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Нестрогое сравнение строк есть только в MySQL');
