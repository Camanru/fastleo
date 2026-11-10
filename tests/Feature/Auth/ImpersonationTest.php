<?php

declare(strict_types=1);

use Fastleo\Auth\Impersonation;
use Fastleo\Auth\LoginRecord;
use Fastleo\Fastleo;
use Fastleo\Notifications\AdminNotification;
use Fastleo\Support\ResourceRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Workbench\App\Models\Feedback;
use Workbench\App\Models\User;

/**
 * Нажать «Войти как» в списке пользователей.
 */
function impersonate(User $target): TestResponse
{
    return test()->post(route('fastleo.resources.action', ['resource' => 'users', 'action' => 'impersonate']), ['ids' => [$target->getKey()]]);
}

/**
 * Войти в панель пользователем, забыв предыдущего.
 */
function actAs(User $user): void
{
    resolve('auth')->forgetGuards();
    test()->flushSession();
    test()->actingAs($user, Fastleo::GUARD);
}

beforeEach(function (): void {
    config(['fastleo.users.impersonate' => true]);
    $this->owner = admin(['email' => 'owner@example.com', 'remember_token' => 'owner-token']);
    $this->manager = admin(['email' => 'manager@example.com', 'name' => 'Manager', 'remember_token' => 'manager-token']);
    Fastleo::resolveOwnerUsing(fn (User $user): bool => $user->email === 'owner@example.com');
    actAs($this->owner);
});

afterEach(function (): void {
    Fastleo::resolveOwnerUsing(null);
});

describe('signing in as another user', function (): void {
    it('signs the owner in as the user, remembers who they are and keeps both remember tokens', function (): void {
        impersonate($this->manager)->assertRedirect(route('fastleo.dashboard'))->assertSessionHas('fastleo.success', 'Вы вошли как Manager.');

        expect(Fastleo::guard()->user()?->getKey())->toBe($this->manager->getKey())
            ->and(Impersonation::active())->toBeTrue()
            ->and(Fastleo::impersonator()?->getKey())->toBe($this->owner->getKey())
            ->and($this->owner->fresh()->remember_token)->toBe('owner-token')
            ->and($this->manager->fresh()->remember_token)->toBe('manager-token');
    });

    it('shows a banner with the name and a button to return, and no link to the profile', function (): void {
        impersonate($this->manager);

        $this->get(route('fastleo.dashboard'))
            ->assertOk()
            ->assertSee('data-impersonating', false)
            ->assertSee('Вы вошли как Manager')
            ->assertSee(route('fastleo.impersonate.leave'), false)
            ->assertDontSee('href="'.route('fastleo.profile.show').'"', false);
    });

    it('escapes the name of the user in the banner', function (): void {
        $this->manager->forceFill(['name' => '<img src=x onerror=alert(1)>'])->save();
        impersonate($this->manager);

        $this->get(route('fastleo.dashboard'))->assertOk()->assertDontSee('<img src=x onerror=alert(1)>', false)->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
    });

    it('does not write the sign-in to the history and does not send the "new device" email, neither on the way in nor back', function (): void {
        Notification::fake();
        LoginRecord::query()->create(['user_id' => (string) $this->manager->getKey(), 'fingerprint' => sha1('another device'), 'ip' => '10.0.0.1', 'user_agent' => 'Old', 'created_at' => now(), 'last_seen_at' => now()]);
        LoginRecord::query()->create(['user_id' => (string) $this->owner->getKey(), 'fingerprint' => sha1('another device'), 'ip' => '10.0.0.1', 'user_agent' => 'Old', 'created_at' => now(), 'last_seen_at' => now()]);

        impersonate($this->manager);
        $this->post(route('fastleo.impersonate.leave'));

        expect(LoginRecord::query()->count())->toBe(2);
        Notification::assertNothingSent();
    });

    it('is off by default', function (): void {
        config(['fastleo.users.impersonate' => false]);

        impersonate($this->manager)->assertForbidden();

        expect(Fastleo::guard()->user()?->getKey())->toBe($this->owner->getKey())
            ->and(Impersonation::active())->toBeFalse();
    });

    it('does nothing when the site does not name an owner', function (): void {
        Fastleo::resolveOwnerUsing(null);

        impersonate($this->manager)->assertForbidden();

        expect(Impersonation::enabled())->toBeFalse();
    });

    it('is only for the owner', function (): void {
        $other = admin(['email' => 'other@example.com']);
        actAs($this->manager);

        impersonate($other)->assertForbidden();

        expect(Fastleo::guard()->user()?->getKey())->toBe($this->manager->getKey());
    });

    it('does not sign in as another owner or as oneself', function (): void {
        $second = admin(['email' => 'owner@example.com.ru']);
        Fastleo::resolveOwnerUsing(fn (User $user): bool => str_starts_with($user->email, 'owner@example.com'));

        impersonate($second)->assertForbidden();
        impersonate($this->owner)->assertForbidden();

        expect(Impersonation::active())->toBeFalse();
    });

    it('does not sign in as a user without access to the panel', function (): void {
        $without = User::factory()->create(['email' => 'without@example.com', 'fastleo_admin' => false]);

        impersonate($without)->assertNotFound();

        expect(Impersonation::mayStart($this->owner, $without))->toBeFalse()
            ->and(Impersonation::active())->toBeFalse();
    });

    it('does not offer the button on the card of another owner or without the option', function (): void {
        $this->get(route('fastleo.dashboard'));
        $action = Fastleo::resources()->find('users')->listActions()['impersonate'];

        expect($action->allows($this->manager))->toBeTrue()
            ->and($action->allows($this->owner))->toBeFalse()
            ->and($action->isAvailableInMcp())->toBeFalse();

        config(['fastleo.users.impersonate' => false]);

        expect($action->allows($this->manager))->toBeFalse();
    });

    it('shows the button in the list of users', function (): void {
        $this->get(route('fastleo.resources.index', 'users'))->assertOk()->assertSee('Войти как')->assertSee('actions/impersonate', false);
    });

    it('refuses to sign in as the owner even when the action is called directly', function (): void {
        $this->get(route('fastleo.dashboard'));
        $action = Fastleo::resources()->find('users')->listActions()['impersonate'];

        expect(fn () => $action->handle(new Collection([$this->owner]), []))->toThrow(HttpException::class);
    });

    it('stops after ten sign-ins in a minute', function (): void {
        foreach (range(1, 10) as $_) {
            impersonate($this->manager)->assertRedirect();
            $this->post(route('fastleo.impersonate.leave'));
        }

        impersonate($this->manager)->assertStatus(429);

        RateLimiter::clear('fastleo-impersonate:'.$this->owner->getKey());
    });

    it('is not possible without a session', function (): void {
        app()->instance('request', Request::create('/'));

        expect(Impersonation::mayStart($this->owner, $this->manager))->toBeFalse()
            ->and(Impersonation::active())->toBeFalse()
            ->and(Fastleo::impersonator())->toBeNull();
    });

    it('is reserved in the registry so that a section cannot take the address', function (): void {
        expect(ResourceRegistry::RESERVED_SLUGS)->toContain('impersonate');
    });
});

describe('while signed in as another user', function (): void {
    beforeEach(function (): void {
        impersonate($this->manager);
    });

    it('closes what would write or hand out the account: profile, reading notifications, dashboard layout', function (): void {
        $this->get(route('fastleo.profile.show'))->assertForbidden();
        $this->put(route('fastleo.profile.password'), [])->assertForbidden();
        $this->post(route('fastleo.profile.logout-others'), [])->assertForbidden();
        $this->post(route('fastleo.profile.two-factor.enable'), [])->assertForbidden();
        $this->post(route('fastleo.notifications.read-all'))->assertForbidden();
        config(['fastleo.notifications.bell' => true]);
        $notification = AdminNotification::query()->create(['user_id' => (string) $this->manager->id, 'data' => ['title' => 'Новая заявка'], 'created_at' => now()]);
        $this->post(route('fastleo.notifications.read', $notification->id))->assertForbidden();
        expect($notification->fresh()?->read_at)->toBeNull();
        $this->put(route('fastleo.dashboard.layout.save'), [])->assertForbidden();
        $this->delete(route('fastleo.dashboard.layout.reset'))->assertForbidden();
    });

    it('does not mark an opened record as read, so it stays new for the user', function (): void {
        $feedback = Feedback::factory()->create(['read_at' => null]);

        $this->get(route('fastleo.resources.show', ['feedback', $feedback->id]))->assertOk();

        expect($feedback->fresh()?->read_at)->toBeNull();
    });

    it('does not ask for the two-factor protection that the user has not set up, the owner has already passed it', function (): void {
        config(['fastleo.auth.two_factor_required' => true]);

        $this->get(route('fastleo.dashboard'))->assertOk();
    });

    it('tells who really works only while the impersonation is valid', function (): void {
        expect(Fastleo::impersonator()?->getKey())->toBe($this->owner->getKey());

        $this->travel(Impersonation::LIFETIME + 1)->seconds();

        expect(Fastleo::impersonator())->toBeNull();
    });

    it('returns the owner to the users list, drops the state and keeps the remember tokens', function (): void {
        $this->post(route('fastleo.impersonate.leave'))
            ->assertRedirect(route('fastleo.resources.index', 'users', false))
            ->assertSessionHas('fastleo.success', 'Вы вернулись в свою учётную запись.');

        expect(Fastleo::guard()->user()?->getKey())->toBe($this->owner->getKey())
            ->and(Impersonation::active())->toBeFalse()
            ->and(Fastleo::impersonator())->toBeNull()
            ->and($this->owner->fresh()->remember_token)->toBe('owner-token')
            ->and($this->manager->fresh()->remember_token)->toBe('manager-token');
    });

    it('signs out the device without touching the remember token of the user', function (): void {
        $this->post(route('fastleo.logout'))->assertRedirect(route('fastleo.login'));

        $this->assertGuest(Fastleo::GUARD);
        expect($this->manager->fresh()->remember_token)->toBe('manager-token')
            ->and($this->owner->fresh()->remember_token)->toBe('owner-token');
    });

    it('signs out when the user loses access, without touching their remember token', function (): void {
        $this->manager->forceFill(['fastleo_admin' => false])->save();
        resolve('auth')->forgetGuards();

        $this->get(route('fastleo.dashboard'))->assertRedirect(route('fastleo.login'));

        $this->assertGuest(Fastleo::GUARD);
        expect($this->manager->fresh()->remember_token)->toBe('manager-token');
    });

    it('signs out when the owner stops being the owner', function (): void {
        Fastleo::resolveOwnerUsing(fn (): bool => false);

        $this->get(route('fastleo.dashboard'))->assertRedirect(route('fastleo.login'));

        $this->assertGuest(Fastleo::GUARD);
    });

    it('signs out when the owner has changed the password after the start', function (): void {
        $this->owner->forceFill(['password' => Hash::make('new-password-1')])->save();

        $this->get(route('fastleo.dashboard'))->assertRedirect(route('fastleo.login'));

        $this->assertGuest(Fastleo::GUARD);
    });

    it('signs out when the owner has been deleted', function (): void {
        $this->owner->delete();

        $this->get(route('fastleo.dashboard'))->assertRedirect(route('fastleo.login'));

        $this->assertGuest(Fastleo::GUARD);
    });

    it('signs out once the option is turned off', function (): void {
        config(['fastleo.users.impersonate' => false]);

        $this->get(route('fastleo.dashboard'))->assertRedirect(route('fastleo.login'));

        $this->assertGuest(Fastleo::GUARD);
    });

    it('signs out after an hour', function (): void {
        $this->travel(Impersonation::LIFETIME + 1)->seconds();

        $this->get(route('fastleo.dashboard'))->assertRedirect(route('fastleo.login'));

        $this->assertGuest(Fastleo::GUARD);
    });

    it('signs out when the user has become an owner', function (): void {
        Fastleo::resolveOwnerUsing(fn (User $user): bool => in_array($user->email, ['owner@example.com', 'manager@example.com'], true));

        $this->get(route('fastleo.dashboard'))->assertRedirect(route('fastleo.login'));

        $this->assertGuest(Fastleo::GUARD);
    });

    it('answers 403 to a JSON request when the state is no longer valid', function (): void {
        Fastleo::resolveOwnerUsing(fn (): bool => false);

        $this->getJson(route('fastleo.dashboard'))->assertForbidden();
    });

    it('forgets the state when someone else signs in through the form', function (): void {
        $third = admin(['email' => 'third@example.com']);

        login(['email' => $third->email, 'password' => 'password']);

        expect(Impersonation::active())->toBeFalse();
        $this->post(route('fastleo.impersonate.leave'))->assertRedirect(route('fastleo.dashboard'));
        expect(Fastleo::guard()->user()?->getKey())->toBe($third->getKey());
    });

    it('signs out and does not return the owner when someone else is in the session than the user it started with', function (): void {
        $third = admin(['email' => 'third@example.com']);
        resolve('auth')->forgetGuards();
        $this->actingAs($third, Fastleo::GUARD);

        // Сверка напрямую: на запросе такого пользователя раньше выкинет проверка хеша пароля в сессии (AuthenticateSession).
        expect(Impersonation::active())->toBeTrue()
            ->and(Impersonation::valid())->toBeFalse()
            ->and(Impersonation::impersonator())->toBeNull();

        $this->post(route('fastleo.impersonate.leave'))->assertRedirect(route('fastleo.login'));

        expect(Fastleo::guard()->user()?->getKey())->not->toBe($this->owner->getKey());
    });

    it('forgets the state when someone else signs in through the form with the two-factor step', function (): void {
        $third = admin(['email' => 'third@example.com']);
        ['secret' => $secret] = withTwoFactor($third);

        login(['email' => $third->email, 'password' => 'password'])->assertRedirect(route('fastleo.two-factor.challenge'));
        $this->post(route('fastleo.two-factor.challenge.store'), ['code' => totp($secret, 1)])->assertRedirect();

        expect(Impersonation::active())->toBeFalse();
        $this->post(route('fastleo.impersonate.leave'))->assertRedirect(route('fastleo.dashboard'));
        expect(Fastleo::guard()->user()?->getKey())->toBe($third->getKey());
    });

    it('refuses to return when the owner can no longer be trusted, as a plain sign-out', function (): void {
        expect(Impersonation::valid())->toBeTrue();

        $this->owner->forceFill(['password' => Hash::make('new-password-1')])->save();

        expect(Impersonation::leave())->toBeFalse();
    });
});

describe('returning without having signed in as anyone', function (): void {
    it('sends the owner to the start page and signs nobody out', function (): void {
        $this->post(route('fastleo.impersonate.leave'))->assertRedirect(route('fastleo.dashboard'));

        expect(Fastleo::guard()->user()?->getKey())->toBe($this->owner->getKey());
    });

    it('is closed for a guest', function (): void {
        resolve('auth')->forgetGuards();
        $this->flushSession();

        $this->post(route('fastleo.impersonate.leave'))->assertRedirect(route('fastleo.login'));
    });
});

it('recognises a state of the wrong shape as no impersonation', function (): void {
    // Нужна сессия запроса: без неё active() возвращает false ещё до разбора состояния.
    $this->get(route('fastleo.dashboard'));
    expect(request()->hasSession())->toBeTrue();

    session()->put(Impersonation::SESSION_KEY, 'garbage');

    expect(Impersonation::active())->toBeFalse();

    session()->put(Impersonation::SESSION_KEY, ['owner' => 1]);

    expect(Impersonation::active())->toBeFalse();
});

it('keeps the users section and the doctor working with a published config that has no impersonate key', function (): void {
    // Блок `users` из опубликованного конфига прошлой версии: слияние неглубокое, ключа `impersonate` нет вовсе.
    config(['fastleo.users' => ['resource' => config('fastleo.users.resource'), 'label' => null, 'singular' => null, 'access' => null]]);

    $this->get(route('fastleo.resources.index', 'users'))->assertOk();
    $this->get(route('fastleo.resources.edit', ['users', $this->manager->getKey()]))->assertOk();

    expect(Impersonation::enabled())->toBeFalse();

    // Doctor не падает на отсутствующем ключе и ничего не говорит про «Войти как» (прочие замечания окружения не важны).
    Artisan::call('fastleo:doctor', ['--json' => true]);
    $problems = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['problems'];

    expect(array_filter($problems, fn (array $problem): bool => str_contains($problem['message'], 'Войти как')))->toBe([]);
});
