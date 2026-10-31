<?php

declare(strict_types=1);

use Fastleo\Auth\FastleoUser;
use Fastleo\Auth\TwoFactor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\User as PlainUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Workbench\App\Models\User;

it('creates an admin from options', function (): void {
    $this->artisan('fastleo:user', [
        'email' => ' Admin@Example.com ',
        '--name' => 'Иван',
        '--password' => 'secret-password',
        '--no-interaction' => true,
    ])->expectsOutputToContain('Администратор admin@example.com создан.')->assertSuccessful();

    $user = User::query()->sole();
    expect($user->email)->toBe('admin@example.com')
        ->and($user->name)->toBe('Иван')
        ->and($user->fastleo_admin)->toBeTrue()
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});

it('asks for the email, name and password when they are not given', function (): void {
    $this->artisan('fastleo:user')
        ->expectsQuestion('Email администратора', 'admin@example.com')
        ->expectsQuestion('Имя', 'Иван')
        ->expectsQuestion('Пароль', 'secret-password')
        ->assertSuccessful();

    $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'name' => 'Иван', 'fastleo_admin' => true]);
});

it('grants access to an existing user and keeps the password when none is entered', function (): void {
    $user = User::factory()->create(['email' => 'user@example.com', 'name' => 'Пётр']);
    $hash = $user->password;

    $this->artisan('fastleo:user', ['email' => 'user@example.com'])
        ->expectsQuestion('Новый пароль (пусто — оставить прежний)', '')
        ->expectsOutputToContain('Пользователю user@example.com выдан доступ в админку.')
        ->assertSuccessful();

    $user->refresh();
    expect($user->fastleo_admin)->toBeTrue()
        ->and($user->name)->toBe('Пётр')
        ->and($user->password)->toBe($hash);
});

it('changes the password of an existing user when given', function (): void {
    $user = User::factory()->create(['email' => 'user@example.com']);

    $this->artisan('fastleo:user', ['email' => 'user@example.com', '--password' => 'new-password'])->assertSuccessful();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

it('uses the part before @ as the name in non-interactive mode', function (): void {
    $this->artisan('fastleo:user', ['email' => 'ivan@example.com', '--password' => 'secret-password', '--no-interaction' => true])
        ->assertSuccessful();

    $this->assertDatabaseHas('users', ['email' => 'ivan@example.com', 'name' => 'ivan']);
});

it('rejects invalid input without creating a user', function (array $arguments, string $message): void {
    $this->artisan('fastleo:user', [...$arguments, '--no-interaction' => true])
        ->expectsOutputToContain($message)
        ->assertFailed();

    $this->assertDatabaseCount('users', 0);
})->with([
    'bad email' => [['email' => 'not-an-email', '--password' => 'secret-password'], 'Укажите правильный email.'],
    'short password' => [['email' => 'admin@example.com', '--password' => 'short'], 'не меньше 8'],
    'no password for a new user' => [['email' => 'admin@example.com'], 'Пароль нового администратора обязателен.'],
]);

it('fails when the user model does not implement FastleoUser', function (): void {
    config(['auth.providers.users.model' => PlainUser::class]);

    $this->artisan('fastleo:user', ['email' => 'admin@example.com', '--password' => 'secret-password'])
        ->expectsOutputToContain(FastleoUser::class)
        ->assertFailed();
});

it('grants access to a user whose email has capital letters instead of creating a second one', function (): void {
    $user = User::factory()->create(['email' => 'Admin@Example.com']);

    $this->artisan('fastleo:user', ['email' => 'admin@example.com', '--no-interaction' => true])->assertSuccessful();

    $this->assertDatabaseCount('users', 1);
    $user->refresh();
    expect($user->email)->toBe('admin@example.com')
        ->and($user->fastleo_admin)->toBeTrue();
});

it('stops when several users differ only in letter case', function (): void {
    User::factory()->create(['email' => 'Admin@Example.com']);
    User::factory()->create(['email' => 'admin@example.com']);

    $this->artisan('fastleo:user', ['email' => 'admin@example.com', '--no-interaction' => true])
        ->expectsOutputToContain('Найдено несколько пользователей')
        ->assertFailed();

    $this->assertDatabaseMissing('users', ['fastleo_admin' => true]);
})->skip(fn (): bool => in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true), 'MySQL не хранит адреса, различающиеся только регистром');

it('explains that the database sees the email as an existing one instead of crashing', function (): void {
    User::creating(function (): never {
        throw new UniqueConstraintViolationException('sqlite', 'insert into users', [], new PDOException('UNIQUE constraint failed: users.email'));
    });

    $this->artisan('fastleo:user', ['email' => 'ivan@example.com', '--password' => 'secret-password', '--no-interaction' => true])
        ->expectsOutputToContain('ivan@example.com база считает')
        ->assertFailed();

    $this->assertDatabaseCount('users', 0);
});

it('uses the user provider of a guard described by the site', function (): void {
    config([
        'auth.providers.admins' => ['driver' => 'eloquent', 'model' => PlainUser::class],
        'auth.guards.fastleo.provider' => 'admins',
    ]);

    $this->artisan('fastleo:user', ['email' => 'admin@example.com', '--password' => 'secret-password'])
        ->expectsOutputToContain(PlainUser::class)
        ->assertFailed();
});

it('does not give access to a user whose email only looks like the typed one', function (): void {
    $ivan = User::factory()->create(['email' => 'ivan@example.com']);

    $exit = Artisan::call('fastleo:user', ['email' => 'ívan@example.com', '--password' => 'secret-password', '--no-interaction' => true]);

    expect($ivan->refresh()->fastleo_admin)->toBeFalse();

    // MySQL с регистронезависимой сортировкой считает адреса одинаковыми и отказывает; остальные базы создают отдельного пользователя.
    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        expect($exit)->toBe(1)->and(Artisan::output())->toContain('база считает таким же')->and(User::query()->count())->toBe(1);
    } else {
        expect($exit)->toBe(0)->and(User::query()->where('email', 'ívan@example.com')->value('fastleo_admin'))->toBeTrue();
    }
});

it('does not create a user when the email of a two-factor reset has a typo', function (): void {
    $this->artisan('fastleo:user', ['email' => 'typo@example.com', '--reset-two-factor' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Пользователь typo@example.com не найден')
        ->assertFailed();

    $this->assertDatabaseMissing('users', ['email' => 'typo@example.com']);
});

it('turns off two-factor protection of a user who lost the phone and keeps everything else', function (): void {
    $admin = admin(['email' => 'anna@example.com']);
    withTwoFactor($admin);

    $this->artisan('fastleo:user', ['email' => 'anna@example.com', '--reset-two-factor' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Двухфакторная защита для anna@example.com отключена.')
        ->assertSuccessful();

    expect(resolve(TwoFactor::class)->isEnabled($admin))->toBeFalse()
        ->and(Hash::check('password', $admin->refresh()->password))->toBeTrue();
});
