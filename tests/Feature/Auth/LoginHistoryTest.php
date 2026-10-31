<?php

declare(strict_types=1);

use Fastleo\Auth\LoginHistory;
use Fastleo\Auth\LoginRecord;
use Fastleo\Auth\NewLoginNotification;
use Fastleo\Fastleo;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    admin(['email' => 'anna@example.com']);
});

function signInFrom(string $agent, string $ip = '10.0.0.1'): void
{
    test()->flushSession();
    test()->withServerVariables(['REMOTE_ADDR' => $ip])->withHeader('User-Agent', $agent)
        ->post(route('fastleo.login.store'), ['email' => 'anna@example.com', 'password' => 'password']);
}

it('records the device on login and does not email the first login', function (): void {
    signInFrom('Chrome');

    $record = LoginRecord::query()->sole();
    expect($record->ip)->toBe('10.0.0.1')
        ->and($record->user_agent)->toBe('Chrome')
        ->and($record->created_at)->not->toBeNull();
    Notification::assertNothingSent();
});

it('updates the same device instead of adding a row', function (): void {
    signInFrom('Chrome');
    $this->travel(2)->hours();
    signInFrom('Chrome');

    $record = LoginRecord::query()->sole();
    expect($record->last_seen_at?->gt($record->created_at))->toBeTrue();
    Notification::assertNothingSent();
});

it('emails the admin about a login from a new browser or address', function (): void {
    signInFrom('Chrome');
    signInFrom('Firefox');
    signInFrom('Chrome', '10.0.0.2');

    expect(LoginRecord::query()->count())->toBe(3);
    Notification::assertSentOnDemandTimes(NewLoginNotification::class, 2);
    Notification::assertSentOnDemand(NewLoginNotification::class, fn ($notification, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'anna@example.com');
});

it('does not email when new_login_mail is off', function (): void {
    config(['fastleo.auth.new_login_mail' => false]);

    signInFrom('Chrome');
    signInFrom('Firefox');

    Notification::assertNothingSent();
});

it('renders the new login mail with time, address and a link to the profile', function (): void {
    $notification = new NewLoginNotification('10.0.0.9', 'Firefox', now());
    app()->setLocale($notification->locale ?? 'en');
    $mail = $notification->toMail(new stdClass);

    expect($notification->locale)->toBe('ru')
        ->and($mail->subject)->toContain('нового устройства')
        ->and(implode(' ', $mail->introLines))->toContain('10.0.0.9')->toContain('Firefox')
        ->and($mail->actionUrl)->toBe(route('fastleo.profile.show'));
});

it('builds the link of the new login mail from APP_URL, not from the Host header', function (): void {
    config(['app.url' => 'https://site.test']);
    $this->get('http://evil.test/fastleo/login');

    $mail = new NewLoginNotification('10.0.0.9', 'Firefox', now())->toMail(new stdClass);

    expect($mail->actionUrl)->toBe('https://site.test/fastleo/profile');
});

it('does not fail the login when the mail cannot be sent', function (): void {
    signInFrom('Chrome');
    Notification::shouldReceive('route')->andThrow(new RuntimeException('smtp down'));

    signInFrom('Firefox');

    $this->assertAuthenticated('fastleo');
});

it('prunes devices not seen for a long time', function (): void {
    signInFrom('Chrome');
    LoginRecord::query()->update(['last_seen_at' => now()->subDays(100)]);
    signInFrom('Firefox');

    $this->artisan('fastleo:prune')->assertSuccessful();

    expect(LoginRecord::query()->pluck('user_agent')->all())->toBe(['Firefox']);
    expect(resolve(LoginHistory::class)->prune())->toBe(0);
});

it('does not record logins on other guards', function (): void {
    event(new Login('web', admin(['email' => 'site@example.com']), false));

    expect(LoginRecord::query()->count())->toBe(0);
});

it('does not try to email an admin who has no address about a new device', function (): void {
    $nameless = admin(['email' => '']);

    foreach (['Chrome', 'Firefox'] as $agent) {
        request()->headers->set('User-Agent', $agent);
        event(new Login(Fastleo::GUARD, $nameless, false));
    }

    expect(LoginRecord::query()->count())->toBe(2);
    Notification::assertNothingSent();
});
