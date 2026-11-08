<?php

declare(strict_types=1);

use Fastleo\Auth\LoginHistory;
use Fastleo\Auth\LoginRecord;
use Fastleo\Auth\NewLoginNotification;
use Fastleo\Fastleo;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Workbench\App\Models\User;

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

it('does not fail when the same new device is recorded by a second request at the same moment', function (): void {
    $user = User::query()->where('email', 'anna@example.com')->sole();
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_USER_AGENT' => 'Chrome']);
    // Второй запрос двойного щелчка «Войти» записывает устройство между проверкой и вставкой первого.
    LoginRecord::creating(function (LoginRecord $record): void {
        DB::table('fastleo_logins')->insert(['user_id' => $record->user_id, 'fingerprint' => $record->fingerprint, 'ip' => '10.0.0.1', 'user_agent' => 'Chrome', 'created_at' => now(), 'last_seen_at' => now()]);
    });

    try {
        $isNew = resolve(LoginHistory::class)->record($user, $request);
    } finally {
        LoginRecord::flushEventListeners();
    }

    // Для устройства, которое уже записал параллельный запрос, оно не новое: письма «новое устройство» не будет.
    expect($isNew)->toBeFalse();
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
    $mail = $notification->toMail(new stdClass());

    expect($notification->locale)->toBe('ru')
        ->and($mail->subject)->toContain('нового устройства')
        ->and(implode(' ', $mail->introLines))->toContain('10.0.0.9')->toContain('Firefox')
        ->and($mail->actionUrl)->toBe(route('fastleo.profile.show'));
});

it('builds the link of the new login mail from APP_URL, not from the Host header', function (): void {
    config(['app.url' => 'https://site.test']);
    $this->get('http://evil.test/fastleo/login');

    $mail = new NewLoginNotification('10.0.0.9', 'Firefox', now())->toMail(new stdClass());

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

it('keeps a device by the first 255 characters of its user agent', function (): void {
    $agent = str_repeat('a', 300);

    signInFrom($agent);
    signInFrom($agent);

    expect(LoginRecord::query()->sole()->user_agent)->toBe(str_repeat('a', 255));
});

it('forgets the devices not seen for 90 days and keeps the others', function (): void {
    $record = fn (string $fingerprint, int $days): LoginRecord => LoginRecord::query()->create([
        'user_id' => '1', 'fingerprint' => $fingerprint, 'ip' => '10.0.0.1', 'user_agent' => null,
        'created_at' => now()->subDays($days), 'last_seen_at' => now()->subDays($days),
    ]);
    $record('fresh', 89);
    $record('stale', 91);

    expect(resolve(LoginHistory::class)->prune())->toBe(1)
        ->and(LoginRecord::query()->pluck('fingerprint')->all())->toBe(['fresh']);
});

it('keeps at least a day of history whatever the setting', function (): void {
    config(['fastleo.auth.logins_keep_days' => 0]);
    $record = fn (string $fingerprint, int $hours): LoginRecord => LoginRecord::query()->create([
        'user_id' => '1', 'fingerprint' => $fingerprint, 'ip' => '10.0.0.1', 'user_agent' => null,
        'created_at' => now()->subHours($hours), 'last_seen_at' => now()->subHours($hours),
    ]);
    $record('today', 12);
    $record('older', 36);

    expect(resolve(LoginHistory::class)->prune())->toBe(1)
        ->and(LoginRecord::query()->pluck('fingerprint')->all())->toBe(['today']);
});

it('does not let the browser string put a link or a picture into the new login mail', function (string $userAgent): void {
    app()->setLocale('ru');
    $html = (string) new NewLoginNotification('10.0.0.9', $userAgent, now())->toMail(new stdClass())->render();

    expect($html)->not->toContain('href="https://evil.example')
        ->and($html)->not->toContain('src="https://evil.example')
        ->and($html)->toContain('Браузер:');
})->with([
    'link' => 'Mozilla [Подтвердите, что это вы](https://evil.example/verify)',
    'picture' => 'Mozilla ![](https://evil.example/pixel.png)',
    'autolink' => 'Mozilla <https://evil.example/verify>',
]);

it('shows a less-than sign in the browser string of the new login mail as it is, not as an escaped entity', function (): void {
    app()->setLocale('ru');
    $html = (string) new NewLoginNotification('10.0.0.9', 'Browser 1 < 2 and 3 > 2', now())->toMail(new stdClass())->render();

    // Laravel сам заменяет «<» на «&lt;»: ещё одно экранирование показало бы в письме «&lt;» буквами.
    expect($html)->toContain('Browser 1 &lt; 2 and 3 &gt; 2')->not->toContain('&amp;lt;');
});

it('cuts a very long browser string in the new login mail', function (): void {
    $mail = new NewLoginNotification('10.0.0.9', str_repeat('Я', 5000), now())->toMail(new stdClass());

    expect(mb_strlen(implode(' ', $mail->introLines)))->toBeLessThan(900);
});
