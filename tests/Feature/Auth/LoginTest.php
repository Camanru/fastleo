<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Models\User;

it('renders the login form for a guest', function (): void {
    $response = $this->get(route('fastleo.login'));

    $response->assertOk()
        ->assertSee('Вход в админку')
        ->assertSee('name="remember"', false);
});

it('logs an admin in and redirects to the dashboard', function (): void {
    $admin = admin(['email' => 'admin@example.com']);

    $response = login(['email' => 'admin@example.com', 'password' => 'password']);

    $response->assertRedirect(route('fastleo.dashboard'));
    $this->assertAuthenticatedAs($admin, Fastleo::GUARD);
});

it('accepts the email in any case and with spaces around it', function (): void {
    $admin = admin(['email' => 'admin@example.com']);

    login(['email' => '  Admin@Example.COM ', 'password' => 'password']);

    $this->assertAuthenticatedAs($admin, Fastleo::GUARD);
});

it('rejects a wrong password with a generic message', function (): void {
    admin(['email' => 'admin@example.com']);

    $response = $this->from(route('fastleo.login'))
        ->post(route('fastleo.login.store'), ['email' => 'admin@example.com', 'password' => 'wrong-password']);

    $response->assertRedirect(route('fastleo.login'))
        ->assertSessionHasErrors(['email' => 'Неверный email или пароль.'])
        ->assertSessionHasInput('email', 'admin@example.com')
        ->assertSessionMissing('_old_input.password');
    $this->assertGuest(Fastleo::GUARD);
});

it('rejects an unknown email with the same message', function (): void {
    $response = login(['email' => 'nobody@example.com', 'password' => 'password']);

    $response->assertSessionHasErrors(['email' => 'Неверный email или пароль.']);
    $this->assertGuest(Fastleo::GUARD);
});

it('rejects a site user without admin access with the same message', function (): void {
    User::factory()->create(['email' => 'user@example.com']);

    $response = login(['email' => 'user@example.com', 'password' => 'password']);

    $response->assertSessionHasErrors(['email' => 'Неверный email или пароль.']);
    $this->assertGuest(Fastleo::GUARD);
});

it('rejects arrays instead of strings', function (): void {
    admin(['email' => 'admin@example.com']);

    $response = login(['email' => ['admin@example.com'], 'password' => ['password']]);

    $response->assertSessionHasErrors(['email' => 'Неверный email или пароль.', 'password' => 'Неверный email или пароль.']);
    $this->assertGuest(Fastleo::GUARD);
});

it('asks for the email and password when they are empty', function (): void {
    $response = login([]);

    $response->assertSessionHasErrors(['email' => 'Введите email.', 'password' => 'Введите пароль.']);
});

it('regenerates the session id on login', function (): void {
    admin(['email' => 'admin@example.com']);
    $this->startSession();
    $before = session()->getId();

    login(['email' => 'admin@example.com', 'password' => 'password']);

    expect(session()->getId())->not->toBe($before);
});

it('does not log the user into the site guard', function (): void {
    admin(['email' => 'admin@example.com']);

    login(['email' => 'admin@example.com', 'password' => 'password']);

    $this->assertGuest('web');
});

it('sets a remember cookie only when asked', function (bool $remember): void {
    admin(['email' => 'admin@example.com']);

    $response = login(['email' => 'admin@example.com', 'password' => 'password', 'remember' => $remember ? '1' : '']);

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($cookie): bool => $cookie->getName() === Fastleo::guard()->getRecallerName());

    expect($cookie !== null)->toBe($remember);
})->with([true, false]);

it('keeps the remembered admin signed in for 30 days', function (): void {
    admin(['email' => 'admin@example.com']);

    $response = login(['email' => 'admin@example.com', 'password' => 'password', 'remember' => '1']);

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($cookie): bool => $cookie->getName() === Fastleo::guard()->getRecallerName());
    expect($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->getTimestamp())
        ->toBeLessThanOrEqual(now()->addDays(30)->addMinute()->getTimestamp());
});

it('signs the admin back in from the remember cookie after the session expires', function (): void {
    admin(['email' => 'admin@example.com']);
    $name = Fastleo::guard()->getRecallerName();
    $cookie = login(['email' => 'admin@example.com', 'password' => 'password', 'remember' => '1'])->getCookie($name);
    Auth::forgetGuards();
    $this->flushSession();

    $response = $this->withCookie($name, $cookie->getValue())->get(route('fastleo.dashboard'));

    $response->assertOk();
});

it('redirects to the page the admin opened before signing in', function (): void {
    admin(['email' => 'admin@example.com']);
    $this->get(route('fastleo.dashboard').'?tab=1');

    $response = login(['email' => 'admin@example.com', 'password' => 'password']);

    $response->assertRedirect(route('fastleo.dashboard').'?tab=1');
});

it('sends a signed-in admin from the login form to the dashboard', function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);

    $response = $this->get(route('fastleo.login'));

    $response->assertRedirect(route('fastleo.dashboard'));
});

it('returns to the admin page the guest opened before the login', function (): void {
    admin(['email' => 'admin@example.com']);

    $this->withSession(['url.intended' => route('fastleo.resources.index', 'articles')])
        ->post(route('fastleo.login.store'), ['email' => 'admin@example.com', 'password' => 'password'])
        ->assertRedirect(route('fastleo.resources.index', 'articles'));
});

it('does not return to a page of the site or of another host after the login', function (string $intended): void {
    admin(['email' => 'admin@example.com']);

    $this->withSession(['url.intended' => $intended])
        ->post(route('fastleo.login.store'), ['email' => 'admin@example.com', 'password' => 'password'])
        ->assertRedirect(route('fastleo.dashboard'))
        ->assertSessionMissing('url.intended');
})->with([
    'page of the site' => fn (): string => url('/blog/post'),
    'another host' => 'https://evil.example/fastleo/articles',
    'path with the admin name as prefix' => fn (): string => route('fastleo.dashboard').'-other',
]);

describe('special hashing settings', function (): void {
    afterEach(fn () => Hash::forgetDrivers());

    it('answers with the usual error when the password is longer than the site allows', function (string $email): void {
        admin(['email' => 'admin@example.com']);
        config(['hashing.bcrypt.limit' => 10]);
        Hash::forgetDrivers();

        $this->from(route('fastleo.login'))
            ->post(route('fastleo.login.store'), ['email' => $email, 'password' => str_repeat('a', 30)])
            ->assertRedirect(route('fastleo.login'))
            ->assertSessionHasErrors(['email' => 'Неверный email или пароль.']);
    })->with(['known email' => 'admin@example.com', 'unknown email' => 'nobody@example.com']);

    it('answers with the usual error for a user whose password is not a bcrypt hash or is empty', function (string $stored): void {
        $admin = admin(['email' => 'admin@example.com']);
        $admin->forceFill(['password' => $stored])->save();
        config(['hashing.bcrypt.verify' => true]);
        Hash::forgetDrivers();

        login(['email' => 'admin@example.com', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Неверный email или пароль.']);
        $this->assertGuest(Fastleo::GUARD);
    })->with(['empty' => '', 'other algorithm' => '5f4dcc3b5aa765d61d8327deb882cf99']);

    it('answers with the usual error when the stored hash was written by another algorithm behind the model cast', function (): void {
        $admin = admin(['email' => 'admin@example.com']);
        DB::table('users')->where('id', $admin->id)->update(['password' => md5('password')]);
        config(['hashing.bcrypt.verify' => true]);
        Hash::forgetDrivers();

        login(['email' => 'admin@example.com', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Неверный email или пароль.']);
        $this->assertGuest(Fastleo::GUARD);
    });

    it('spends the time of a hash check on a user who has no password at all', function (): void {
        Schema::table('users', fn (Blueprint $table) => $table->string('password')->nullable()->change());
        $admin = admin(['email' => 'admin@example.com']);
        $admin->forceFill(['password' => null])->save();
        Hash::spy();

        login(['email' => 'admin@example.com', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Неверный email или пароль.']);

        Hash::shouldHaveReceived('make')->once();
        $this->assertGuest(Fastleo::GUARD);
    });
});
