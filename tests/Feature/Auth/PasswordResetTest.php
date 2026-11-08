<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Fastleo\Auth\ResetPasswordNotification;
use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Models\NoResetUser;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Sleep;
use Workbench\App\Models\User;

beforeEach(function (): void {
    Notification::fake();
    // Каждый ответ ждёт секунду (внешняя рамка): настоящая пауза растянула бы набор на минуты.
    Sleep::fake();
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

it('does not let requests for a reset link block the owner from entering the new password', function (): void {
    $admin = admin(['email' => 'anna@example.com']);
    $token = resetTokenFor($admin);

    // Чужой человек шлёт десять запросов на адрес владельца (с разных IP: на один IP лимит — 5 в минуту).
    foreach (range(1, 10) as $number) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$number])
            ->post(route('fastleo.password.email'), ['email' => 'anna@example.com'])
            ->assertSessionHas('status');
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->post(route('fastleo.password.email'), ['email' => 'anna@example.com'])
        ->assertTooManyRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->post(route('fastleo.password.update'), ['token' => $token, 'email' => 'anna@example.com', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
        ->assertRedirect(route('fastleo.login'))
        ->assertSessionHasNoErrors();

    expect(Hash::check('new-secret-123', $admin->refresh()->password))->toBeTrue();
});

it('limits the attempts to enter a new password per ip', function (): void {
    foreach (range(1, 5) as $_) {
        $this->post(route('fastleo.password.update'), ['token' => 'x', 'email' => 'nobody@example.com', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
            ->assertSessionHasErrors('email');
    }

    $this->post(route('fastleo.password.update'), ['token' => 'x', 'email' => 'nobody@example.com', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
        ->assertTooManyRequests();
});

/**
 * Все запросы сброса пароля, при которых ответ не должен выдавать, есть ли такой администратор.
 *
 * @return array<string, array{string, array<string, string>}>
 */
function resetRequests(): array
{
    $payload = ['password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'];

    return [
        'a link for an admin' => ['fastleo.password.email', ['email' => 'anna@example.com']],
        'a link for an unknown address' => ['fastleo.password.email', ['email' => 'nobody@example.com']],
        'a link for a user without admin access' => ['fastleo.password.email', ['email' => 'user@example.com']],
        'a new password for an unknown address' => ['fastleo.password.update', ['token' => 'x', 'email' => 'nobody@example.com', ...$payload]],
        'a new password for a user without admin access' => ['fastleo.password.update', ['token' => 'x', 'email' => 'user@example.com', ...$payload]],
        'a new password with a wrong token' => ['fastleo.password.update', ['token' => 'x', 'email' => 'anna@example.com', ...$payload]],
    ];
}

/**
 * Отправить запрос сброса пароля и проверить, что ответ вышел не раньше секунды.
 *
 * Рамка ждёт «секунда минус затраченное», а затраченное внутри рамки не больше времени всего запроса. Поэтому пауза лежит
 * между «секунда минус время запроса» и секундой — на любой скорости машины; порог «пауза не меньше 0,9 с» падал бы
 * под нагрузкой, когда сам запрос занимает больше 0,1 с. Рамка брокера паролей отключена (0), чтобы в ответе была
 * одна пауза: внешняя.
 *
 * @param  array<string, string>  $data
 */
function assertAnsweredNoSoonerThanASecond(string $route, array $data): void
{
    config(['auth.timebox_duration' => 0]);
    Sleep::fake();

    $started = hrtime(true);
    test()->post(route($route), $data);
    $elapsed = (hrtime(true) - $started) / 1000;

    $slept = null;
    Sleep::assertSlept(function (CarbonInterval $pause) use (&$slept): bool {
        $slept = $pause->totalMicroseconds;

        return true;
    }, 1);

    expect($slept)->toBeLessThanOrEqual(1_000_000)->toBeGreaterThanOrEqual(1_000_000 - $elapsed - 1000);
}

it('answers no sooner than a second, whatever the branch: the hash alone takes a fifth of it or more, and the admin and the unknown address must not differ', function (string $route, array $data): void {
    admin(['email' => 'anna@example.com']);
    User::factory()->create(['email' => 'user@example.com']);

    assertAnsweredNoSoonerThanASecond($route, $data);
})->with(fn (): array => resetRequests());

it('answers a repeated request within the broker throttle as slowly as the first one', function (): void {
    admin(['email' => 'anna@example.com']);
    // Брокер паролей запоминает рамку при создании, поэтому она отключается до первого запроса.
    config(['auth.timebox_duration' => 0]);

    // Повторный запрос в паузу брокера не создаёт токен и не хеширует: без внешней рамки он отвечал бы заметно быстрее.
    $this->post(route('fastleo.password.email'), ['email' => 'anna@example.com']);

    assertAnsweredNoSoonerThanASecond('fastleo.password.email', ['email' => 'anna@example.com']);
});

it('waits as long as the site asks when that is longer than a second, also when the minimum time is set as a string, as env() gives it', function (mixed $duration): void {
    config(['auth.timebox_duration' => $duration]);
    Sleep::fake();

    $this->post(route('fastleo.password.email'), ['email' => 'nobody@example.com'])->assertSessionHas('status');

    Sleep::assertSlept(fn (CarbonInterval $pause): bool => $pause->totalMicroseconds >= 30_000_000, 1);
})->with([
    'an integer' => 60_000_000,
    'a string' => '60000000',
]);

it('shows the reset form when the address in the link is not a string', function (): void {
    $this->get(route('fastleo.password.reset', ['token' => 'x']).'?email[]=y')
        ->assertOk()
        ->assertSee('name="email"', false);
});

it('answers a reset for a model that cannot reset a password like for a wrong token', function (): void {
    config(['auth.providers.users.model' => NoResetUser::class]);
    DB::table('users')->insert(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'x', 'fastleo_admin' => true]);

    $this->post(route('fastleo.password.update'), ['token' => 'x', 'email' => 'anna@example.com', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
        ->assertSessionHasErrors(['email' => 'Ссылка недействительна или устарела. Запросите новую.']);
});

it('does not put the reset token into the queue tables', function (): void {
    expect(new ResetPasswordNotification('token', 'anna@example.com', 60))->not->toBeInstanceOf(ShouldQueue::class);
});
