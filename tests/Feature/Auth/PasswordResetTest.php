<?php

declare(strict_types=1);

use Fastleo\Auth\ResetPasswordNotification;
use Fastleo\Fastleo;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Workbench\App\Models\User;

beforeEach(function (): void {
    Notification::fake();
});

/**
 * Токен сброса для пользователя (как в письме).
 */
function resetTokenFor(User $user): string
{
    /** @var PasswordBroker $broker */
    $broker = Password::broker('fastleo');

    return $broker->createToken($user);
}

it('shows the forgot password form and links to it from the login form', function (): void {
    $this->get(route('fastleo.login'))->assertSee(route('fastleo.password.request'), false);
    $this->get(route('fastleo.password.request'))->assertOk()->assertSee('Восстановление пароля');
});

it('sends a reset link to an admin', function (): void {
    admin(['email' => 'anna@example.com']);

    $this->post(route('fastleo.password.email'), ['email' => ' Anna@Example.com '])
        ->assertSessionHas('status', 'Если такой администратор есть, мы отправили ему письмо со ссылкой для нового пароля.');

    Notification::assertSentOnDemand(ResetPasswordNotification::class, function ($notification, $channels, $notifiable): bool {
        $mail = $notification->toMail($notifiable);

        return $notifiable->routes['mail'] === 'anna@example.com'
            && str_contains($mail->actionUrl, '/reset-password/')
            && str_contains($mail->actionUrl, 'email=anna%40example.com');
    });
});

it('builds the link from APP_URL and not from the Host header of the request', function (): void {
    config(['app.url' => 'https://site.test']);
    admin(['email' => 'anna@example.com']);

    $this->call('POST', 'http://evil.test/fastleo/forgot-password', ['email' => 'anna@example.com', '_token' => csrf_token()])->assertRedirect();

    Notification::assertSentOnDemand(ResetPasswordNotification::class, function ($notification, $channels, $notifiable): bool {
        $url = $notification->toMail($notifiable)->actionUrl;

        return str_starts_with($url, 'https://site.test/fastleo/reset-password/') && ! str_contains($url, 'evil.test');
    });
});

it('answers the same for an unknown email and for a user without admin access, and sends nothing', function (): void {
    User::factory()->create(['email' => 'user@example.com']);

    $unknown = $this->post(route('fastleo.password.email'), ['email' => 'nobody@example.com']);
    $plain = $this->post(route('fastleo.password.email'), ['email' => 'user@example.com']);

    expect($unknown->getSession()->get('status'))->toBe($plain->getSession()->get('status'))->not->toBeNull();
    Notification::assertNothingSent();
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('does not send a second link within the broker throttle', function (): void {
    admin(['email' => 'anna@example.com']);

    $this->post(route('fastleo.password.email'), ['email' => 'anna@example.com']);
    $this->post(route('fastleo.password.email'), ['email' => 'anna@example.com']);

    Notification::assertSentOnDemandTimes(ResetPasswordNotification::class, 1);
});

it('validates the email field', function (): void {
    $this->post(route('fastleo.password.email'), [])->assertSessionHasErrors('email');
    $this->post(route('fastleo.password.email'), ['email' => ['a']])->assertSessionHasErrors('email');
});

it('does not fail the request when the mail cannot be sent', function (): void {
    admin(['email' => 'anna@example.com']);
    Notification::shouldReceive('route')->andThrow(new RuntimeException('smtp down'));

    $this->post(route('fastleo.password.email'), ['email' => 'anna@example.com'])->assertSessionHas('status');
});

it('limits reset link requests per address and per ip', function (): void {
    foreach (range(1, 5) as $_) {
        $this->post(route('fastleo.password.email'), ['email' => 'nobody@example.com'])->assertSessionHas('status');
    }

    $this->post(route('fastleo.password.email'), ['email' => 'nobody@example.com'])->assertTooManyRequests();
});

it('shows the reset form with the email from the link', function (): void {
    $this->get(route('fastleo.password.reset', ['token' => 'abc', 'email' => 'anna@example.com']))
        ->assertOk()
        ->assertSee('value="anna@example.com"', false)
        ->assertSee('name="token" value="abc"', false);
});

it('resets the password with a valid token, rotates the remember token and does not log in', function (): void {
    $admin = admin(['email' => 'anna@example.com', 'remember_token' => 'old-token']);
    $token = resetTokenFor($admin);

    $this->post(route('fastleo.password.update'), ['token' => $token, 'email' => 'Anna@example.com', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
        ->assertRedirect(route('fastleo.login'))
        ->assertSessionHas('status', 'Пароль изменён. Войдите с новым паролем.');

    $admin->refresh();
    expect(Hash::check('new-secret-123', $admin->password))->toBeTrue()
        ->and($admin->remember_token)->not->toBe('old-token')
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);
    $this->assertGuest(Fastleo::GUARD);

    login(['email' => 'anna@example.com', 'password' => 'new-secret-123'])->assertRedirect(route('fastleo.dashboard'));
});

it('rejects a wrong, reused or foreign token with one generic message', function (): void {
    $admin = admin(['email' => 'anna@example.com']);
    $other = admin(['email' => 'boris@example.com']);
    $token = resetTokenFor($admin);
    $payload = ['password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'];

    $wrong = $this->post(route('fastleo.password.update'), ['token' => 'wrong', 'email' => 'anna@example.com', ...$payload]);
    $foreign = $this->post(route('fastleo.password.update'), ['token' => $token, 'email' => 'boris@example.com', ...$payload]);

    $wrong->assertSessionHasErrors(['email' => 'Ссылка недействительна или устарела. Запросите новую.']);
    $foreign->assertSessionHasErrors(['email' => 'Ссылка недействительна или устарела. Запросите новую.']);
    expect(Hash::check('password', $admin->refresh()->password))->toBeTrue()
        ->and(Hash::check('password', $other->refresh()->password))->toBeTrue();

    $this->post(route('fastleo.password.update'), ['token' => $token, 'email' => 'anna@example.com', ...$payload]);
    $this->post(route('fastleo.password.update'), ['token' => $token, 'email' => 'anna@example.com', 'password' => 'another-secret-1', 'password_confirmation' => 'another-secret-1'])
        ->assertSessionHasErrors('email');
    expect(Hash::check('new-secret-123', $admin->refresh()->password))->toBeTrue();
});

it('rejects an expired token', function (): void {
    $admin = admin(['email' => 'anna@example.com']);
    $token = resetTokenFor($admin);
    $this->travel(61)->minutes();

    $this->post(route('fastleo.password.update'), ['token' => $token, 'email' => 'anna@example.com', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
        ->assertSessionHasErrors('email');
    expect(Hash::check('password', $admin->refresh()->password))->toBeTrue();
});

it('does not reset the password of a user without admin access even with a valid token', function (): void {
    $user = User::factory()->create(['email' => 'user@example.com']);
    $token = resetTokenFor($user);

    $this->post(route('fastleo.password.update'), ['token' => $token, 'email' => 'user@example.com', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
        ->assertSessionHasErrors(['email' => 'Ссылка недействительна или устарела. Запросите новую.']);
    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

it('validates the new password', function (): void {
    $admin = admin(['email' => 'anna@example.com']);
    $token = resetTokenFor($admin);

    $this->post(route('fastleo.password.update'), ['token' => $token, 'email' => 'anna@example.com', 'password' => 'short', 'password_confirmation' => 'short'])
        ->assertSessionHasErrors('password');
    $this->post(route('fastleo.password.update'), ['token' => $token, 'email' => 'anna@example.com', 'password' => 'new-secret-123', 'password_confirmation' => 'different'])
        ->assertSessionHasErrors('password');
    expect(Hash::check('password', $admin->refresh()->password))->toBeTrue();
});

it('ends the sessions of the admin when the password is reset', function (): void {
    $admin = admin(['email' => 'anna@example.com']);
    $this->post(route('fastleo.login.store'), ['email' => 'anna@example.com', 'password' => 'password']);
    $this->get(route('fastleo.dashboard'));
    $session = session()->all();
    $this->flushSession();
    $this->app['auth']->guard(Fastleo::GUARD)->logout();

    $this->post(route('fastleo.password.update'), ['token' => resetTokenFor($admin), 'email' => 'anna@example.com', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123']);

    $this->withSession($session)->actingAs($admin->refresh(), Fastleo::GUARD)
        ->get(route('fastleo.dashboard'))
        ->assertRedirect(route('fastleo.login'));
});
