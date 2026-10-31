<?php

declare(strict_types=1);

use Fastleo\Auth\TwoFactor;
use Fastleo\Auth\TwoFactorCredential;
use Fastleo\Fastleo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

/**
 * Первый шаг входа (email + пароль).
 */
function passwordStep(array $extra = []): TestResponse
{
    return login(['email' => 'anna@example.com', 'password' => 'password', ...$extra]);
}

beforeEach(function (): void {
    $this->admin = admin(['email' => 'anna@example.com']);
});

describe('setup in the profile', function (): void {
    beforeEach(function (): void {
        $this->actingAs($this->admin, Fastleo::GUARD);
    });

    it('needs the current password to start', function (): void {
        $this->post(route('fastleo.profile.two-factor.enable'), ['current_password' => 'wrong'])
            ->assertSessionHasErrorsIn('two_factor', 'current_password');

        expect(TwoFactorCredential::query()->count())->toBe(0);
    });

    it('shows a QR code and the key, then enables after the first valid code and shows recovery codes once', function (): void {
        $this->post(route('fastleo.profile.two-factor.enable'), ['current_password' => 'password'])
            ->assertRedirect(route('fastleo.profile.show'));

        $secret = resolve(TwoFactor::class)->pendingSecret($this->admin);
        $this->get(route('fastleo.profile.show'))
            ->assertOk()
            ->assertSee('<svg', false)
            ->assertSee($secret)
            ->assertSee('Не включена');

        $this->post(route('fastleo.profile.two-factor.confirm'), ['code' => '000000', 'current_password' => 'password'])
            ->assertSessionHasErrorsIn('two_factor', 'code');
        expect(resolve(TwoFactor::class)->isEnabled($this->admin))->toBeFalse();

        $this->followingRedirects()
            ->post(route('fastleo.profile.two-factor.confirm'), ['code' => totp($secret), 'current_password' => 'password'])
            ->assertSee('Двухфакторная защита включена.')
            ->assertSee('data-recovery-codes', false)
            ->assertSee('Включена');

        expect(resolve(TwoFactor::class)->isEnabled($this->admin))->toBeTrue()
            ->and(resolve(TwoFactor::class)->remainingRecoveryCodes($this->admin))->toBe(8);

        // Повторный показ страницы кодов уже не содержит.
        $this->get(route('fastleo.profile.show'))->assertDontSee('data-recovery-codes', false);
    });

    it('shows an unreadable pending secret in the confirmation form of the profile', function (): void {
        $secret = resolve(TwoFactor::class)->begin($this->admin);

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        $this->post(route('fastleo.profile.two-factor.confirm'), ['code' => totp($secret), 'current_password' => 'password'])
            ->assertSessionHasErrorsIn('two_factor', ['code' => 'Секрет двухфакторной защиты не читается: сменился ключ приложения. Попросите владельца сайта выполнить команду fastleo:user с параметром --reset-two-factor.']);
    });

    it('needs the current password to confirm the setup', function (): void {
        $secret = resolve(TwoFactor::class)->begin($this->admin);

        $this->post(route('fastleo.profile.two-factor.confirm'), ['code' => totp($secret)])
            ->assertSessionHasErrorsIn('two_factor', 'current_password');
        $this->post(route('fastleo.profile.two-factor.confirm'), ['code' => totp($secret), 'current_password' => 'wrong'])
            ->assertSessionHasErrorsIn('two_factor', 'current_password');

        expect(resolve(TwoFactor::class)->isEnabled($this->admin))->toBeFalse();
    });

    it('stores the secret encrypted and recovery codes only as hashes', function (): void {
        ['secret' => $secret, 'codes' => $codes] = withTwoFactor($this->admin);

        $row = DB::table('fastleo_two_factor')->first();
        expect($row->secret)->not->toContain($secret)
            ->and(TwoFactorCredential::query()->sole()->secret)->toBe($secret)
            ->and($row->recovery_codes)->not->toContain($codes[0])
            ->and($codes)->toHaveCount(8);
        expect(json_encode($this->admin->toArray()))->not->toContain($secret);
    });

    it('does not replace the secret of an already enabled protection when enable is posted again', function (): void {
        ['secret' => $secret] = withTwoFactor($this->admin);

        $this->post(route('fastleo.profile.two-factor.enable'), ['current_password' => 'password']);

        expect(TwoFactorCredential::query()->sole()->secret)->toBe($secret);
    });

    it('issues new recovery codes and the old ones stop working', function (): void {
        ['codes' => $old] = withTwoFactor($this->admin);

        $this->post(route('fastleo.profile.two-factor.recovery-codes'), ['current_password' => 'wrong'])
            ->assertSessionHasErrorsIn('two_factor', 'current_password');

        $this->post(route('fastleo.profile.two-factor.recovery-codes'), ['current_password' => 'password'])
            ->assertSessionHas('fastleo.recovery_codes', fn (array $codes): bool => count($codes) === 8 && ! array_intersect($codes, $old));

        expect(resolve(TwoFactor::class)->verifyRecoveryCode($this->admin, $old[0]))->toBeFalse();
    });

    it('does not issue recovery codes while the protection is off', function (): void {
        $this->post(route('fastleo.profile.two-factor.recovery-codes'), ['current_password' => 'password'])
            ->assertSessionMissing('fastleo.recovery_codes');
    });

    it('disables the protection with the current password only', function (): void {
        withTwoFactor($this->admin);

        $this->delete(route('fastleo.profile.two-factor.disable'), ['current_password' => 'wrong'])
            ->assertSessionHasErrorsIn('two_factor', 'current_password');
        expect(resolve(TwoFactor::class)->isEnabled($this->admin))->toBeTrue();

        $this->delete(route('fastleo.profile.two-factor.disable'), ['current_password' => 'password'])
            ->assertSessionHas('fastleo.success');
        expect(TwoFactorCredential::query()->count())->toBe(0);
    });
});

describe('login challenge', function (): void {
    it('does not log in after the password alone and asks for a code', function (): void {
        withTwoFactor($this->admin);

        passwordStep()->assertRedirect(route('fastleo.two-factor.challenge'));

        $this->assertGuest(Fastleo::GUARD);
        $this->get(route('fastleo.dashboard'))->assertRedirect(route('fastleo.login'));
        $this->get(route('fastleo.two-factor.challenge'))->assertOk()->assertSee('Код подтверждения');
    });

    it('logs in with a valid code and goes to the intended page', function (): void {
        ['secret' => $secret] = withTwoFactor($this->admin);
        $this->get(route('fastleo.resources.index', 'articles'));

        passwordStep();
        $this->post(route('fastleo.two-factor.challenge.store'), ['code' => totp($secret)])
            ->assertRedirect(route('fastleo.resources.index', 'articles'));

        $this->assertAuthenticatedAs($this->admin, Fastleo::GUARD);
    });

    it('keeps the remember choice made on the password step', function (): void {
        ['secret' => $secret] = withTwoFactor($this->admin);

        passwordStep(['remember' => '1']);
        $response = $this->post(route('fastleo.two-factor.challenge.store'), ['code' => totp($secret)]);

        expect($response->getCookie(Fastleo::guard()->getRecallerName(), false))->not->toBeNull();
    });

    it('accepts a code with spaces', function (): void {
        ['secret' => $secret] = withTwoFactor($this->admin);
        $code = totp($secret);

        passwordStep();
        $this->post(route('fastleo.two-factor.challenge.store'), ['code' => substr($code, 0, 3).' '.substr($code, 3)]);

        $this->assertAuthenticated(Fastleo::GUARD);
    });

    it('rejects a wrong code without logging in', function (): void {
        withTwoFactor($this->admin);

        passwordStep();
        $this->post(route('fastleo.two-factor.challenge.store'), ['code' => '123456'])
            ->assertSessionHasErrors(['code' => 'Неверный или уже использованный код.']);

        $this->assertGuest(Fastleo::GUARD);
    });

    it('does not accept the same code twice', function (): void {
        ['secret' => $secret] = withTwoFactor($this->admin);
        $code = totp($secret);

        passwordStep();
        $this->post(route('fastleo.two-factor.challenge.store'), ['code' => $code]);
        $this->assertAuthenticated(Fastleo::GUARD);

        $this->post(route('fastleo.logout'));
        passwordStep();
        $this->post(route('fastleo.two-factor.challenge.store'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest(Fastleo::GUARD);
    });

    it('does not accept the code that was used to confirm the setup', function (): void {
        $twoFactor = resolve(TwoFactor::class);
        $secret = $twoFactor->begin($this->admin);
        $code = totp($secret);
        $twoFactor->confirm($this->admin, $code);

        expect($twoFactor->verify($this->admin, $code))->toBeFalse();
    });

    it('accepts a recovery code once, in any case', function (): void {
        ['codes' => $codes] = withTwoFactor($this->admin);

        passwordStep();
        $this->post(route('fastleo.two-factor.challenge.store'), ['code' => ' '.strtoupper($codes[0]).' '])->assertRedirect(route('fastleo.dashboard'));
        $this->assertAuthenticated(Fastleo::GUARD);
        expect(resolve(TwoFactor::class)->remainingRecoveryCodes($this->admin))->toBe(7);

        $this->post(route('fastleo.logout'));
        passwordStep();
        $this->post(route('fastleo.two-factor.challenge.store'), ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest(Fastleo::GUARD);
    });

    it('accepts a recovery code with spaces inside', function (): void {
        ['codes' => $codes] = withTwoFactor($this->admin);

        expect(resolve(TwoFactor::class)->verifyRecoveryCode($this->admin, substr($codes[0], 0, 3).' '.substr($codes[0], 3)))->toBeTrue();
    });

    it('accepts one of two parallel requests with the same code', function (): void {
        $twoFactor = resolve(TwoFactor::class);
        $secret = $twoFactor->begin($this->admin);
        $twoFactor->confirm($this->admin, totp($secret, -1));

        // Параллельный запрос успевает записать тот же шаг между чтением записи и её обновлением.
        $raced = false;
        DB::listen(function ($query) use (&$raced): void {
            if (! $raced && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'fastleo_two_factor')) {
                $raced = true;
                DB::table('fastleo_two_factor')->update(['last_step' => new Google2FA()->getTimestamp()]);
            }
        });

        expect($twoFactor->verify($this->admin, totp($secret)))->toBeFalse();
    });

    it('explains an unreadable secret after the application key changed instead of failing', function (): void {
        ['secret' => $secret] = withTwoFactor($this->admin);
        passwordStep();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        $this->post(route('fastleo.two-factor.challenge.store'), ['code' => totp($secret, 1)])
            ->assertSessionHasErrors(['code' => 'Секрет двухфакторной защиты не читается: сменился ключ приложения. Попросите владельца сайта выполнить команду fastleo:user с параметром --reset-two-factor.']);
        $this->assertGuest(Fastleo::GUARD);
    });

    it('starts the setup again when the pending secret is unreadable', function (): void {
        resolve(TwoFactor::class)->begin($this->admin);

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        expect(resolve(TwoFactor::class)->pendingSecret($this->admin))->toBeNull();
        $this->actingAs($this->admin, Fastleo::GUARD)->get(route('fastleo.profile.show'))->assertOk();
    });

    it('sends a visitor without the first step back to the login form', function (): void {
        $this->get(route('fastleo.two-factor.challenge'))->assertRedirect(route('fastleo.login'));
        $this->post(route('fastleo.two-factor.challenge.store'), ['code' => '123456'])->assertRedirect(route('fastleo.login'));
    });

    it('forgets the first step after 10 minutes', function (): void {
        ['secret' => $secret] = withTwoFactor($this->admin);

        passwordStep();
        $this->travel(11)->minutes();

        $this->post(route('fastleo.two-factor.challenge.store'), ['code' => totp($secret)])->assertRedirect(route('fastleo.login'));
        $this->assertGuest(Fastleo::GUARD);
    });

    it('drops the challenge when the admin lost access or protection in the meantime', function (): void {
        ['secret' => $secret] = withTwoFactor($this->admin);
        passwordStep();
        $this->admin->forceFill(['fastleo_admin' => false])->save();

        $this->post(route('fastleo.two-factor.challenge.store'), ['code' => totp($secret)])->assertRedirect(route('fastleo.login'));

        $this->assertGuest(Fastleo::GUARD);
    });

    it('validates the code field', function (): void {
        withTwoFactor($this->admin);
        passwordStep();

        $this->post(route('fastleo.two-factor.challenge.store'), [])->assertSessionHasErrors('code');
        $this->post(route('fastleo.two-factor.challenge.store'), ['code' => ['1']])->assertSessionHasErrors('code');
    });

    it('does not use the login throttle of the password step to reset code attempts', function (): void {
        withTwoFactor($this->admin);

        foreach (range(1, 5) as $_) {
            passwordStep();
            $this->post(route('fastleo.two-factor.challenge.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        }

        passwordStep();
        $this->post(route('fastleo.two-factor.challenge.store'), ['code' => '000000'])
            ->assertSessionHasErrors('code');
        expect(session('errors')->first('code'))->toContain('Слишком много попыток');
        $this->assertGuest(Fastleo::GUARD);
    });

    it('resets the attempts after a successful login', function (): void {
        ['secret' => $secret] = withTwoFactor($this->admin);

        foreach (range(1, 3) as $_) {
            passwordStep();
            $this->post(route('fastleo.two-factor.challenge.store'), ['code' => '000000']);
        }

        passwordStep();
        $this->post(route('fastleo.two-factor.challenge.store'), ['code' => totp($secret)]);
        $this->assertAuthenticated(Fastleo::GUARD);
    });
});

describe('service', function (): void {
    it('rejects codes of a wrong shape and unconfirmed setups', function (): void {
        $twoFactor = resolve(TwoFactor::class);
        $secret = $twoFactor->begin($this->admin);

        expect($twoFactor->isPending($this->admin))->toBeTrue()
            ->and($twoFactor->verify($this->admin, totp($secret)))->toBeFalse()
            ->and($twoFactor->verifyRecoveryCode($this->admin, 'abcde-fghij'))->toBeFalse()
            ->and($twoFactor->confirm($this->admin, 'abcdef'))->toBeNull()
            ->and($twoFactor->confirm($this->admin, '12345'))->toBeNull();

        $twoFactor->confirm($this->admin, totp($secret, -1));
        expect($twoFactor->isPending($this->admin))->toBeFalse()
            ->and($twoFactor->confirm($this->admin, totp($secret)))->toBeNull()
            ->and($twoFactor->verify($this->admin, 'abcdef'))->toBeFalse();
    });

    it('has no secret for a user who never started', function (): void {
        $twoFactor = resolve(TwoFactor::class);

        expect($twoFactor->isEnabled($this->admin))->toBeFalse()
            ->and($twoFactor->pendingSecret($this->admin))->toBeNull()
            ->and($twoFactor->remainingRecoveryCodes($this->admin))->toBe(0)
            ->and($twoFactor->verify($this->admin, '123456'))->toBeFalse()
            ->and($twoFactor->verifyRecoveryCode($this->admin, 'abcde-fghij'))->toBeFalse();
    });

    it('keeps the secret of enabled protection when the setup is started again', function (): void {
        $twoFactor = resolve(TwoFactor::class);
        $enabled = withTwoFactor($this->admin);

        expect($twoFactor->begin($this->admin))->toBe($enabled['secret'])
            ->and($twoFactor->isEnabled($this->admin))->toBeTrue()
            ->and($twoFactor->verify($this->admin, totp($enabled['secret'], 1)))->toBeTrue();
    });

    it('builds the otpauth address with the site name and the email', function (): void {
        $twoFactor = resolve(TwoFactor::class);
        $url = $twoFactor->otpauthUrl($this->admin, 'JBSWY3DPEHPK3PXP');

        expect($url)->toStartWith('otpauth://totp/')->toContain('anna%40example.com')->toContain('secret=JBSWY3DPEHPK3PXP');
    });
});

describe('required protection', function (): void {
    beforeEach(function (): void {
        config(['fastleo.auth.two_factor_required' => true]);
        $this->actingAs($this->admin, Fastleo::GUARD);
    });

    it('sends an admin without protection to the profile from every other page', function (): void {
        $this->get(route('fastleo.dashboard'))->assertRedirect(route('fastleo.profile.show'));
        $this->get(route('fastleo.resources.index', 'articles'))->assertRedirect(route('fastleo.profile.show'));
        $this->getJson(route('fastleo.dashboard'))->assertForbidden();
    });

    it('leaves the profile, the setup and the logout open', function (): void {
        $this->get(route('fastleo.profile.show'))->assertOk();
        $this->post(route('fastleo.profile.two-factor.enable'), ['current_password' => 'password'])->assertRedirect(route('fastleo.profile.show'));
        $this->post(route('fastleo.logout'))->assertRedirect(route('fastleo.login'));
    });

    it('lets an admin with protection work', function (): void {
        withTwoFactor($this->admin);

        $this->get(route('fastleo.dashboard'))->assertOk();
    });

    it('is off by default', function (): void {
        config(['fastleo.auth.two_factor_required' => false]);

        $this->get(route('fastleo.dashboard'))->assertOk();
    });
});
