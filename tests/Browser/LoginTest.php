<?php

declare(strict_types=1);

use Fastleo\Auth\TwoFactorCredential;
use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\User;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['name' => 'Иван', 'email' => 'admin@example.com']);
});

it('signs in, switches to the dark theme that survives a reload and signs out', function (): void {
    $page = visit(route('fastleo.login'));

    $page->assertSee('Вход в админку')
        ->fill('email', 'admin@example.com')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertSee('Здравствуйте, Иван!')
        ->assertScript("document.documentElement.classList.contains('dark')", false)
        ->click('[data-theme-mode="dark"]')
        ->assertScript("document.documentElement.classList.contains('dark')")
        ->refresh()
        ->assertScript("document.documentElement.classList.contains('dark')")
        ->assertScript("getComputedStyle(document.body).backgroundColor !== 'rgb(249, 250, 251)'")
        ->click('[data-theme-mode="light"]')
        ->press('Выйти')
        ->assertSee('Вход в админку')
        ->assertNoJavaScriptErrors();
});

it('shows the error for a wrong password', function (): void {
    $page = visit(route('fastleo.login'));

    $page->fill('email', 'admin@example.com')
        ->fill('password', 'wrong-password')
        ->press('Войти')
        ->assertSee('Неверный email или пароль.')
        ->assertValue('email', 'admin@example.com');
});

it('opens and closes the menu on a phone', function (): void {
    $page = visit(route('fastleo.login'))->resize(390, 844);

    $page->fill('email', 'admin@example.com')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertScript("document.getElementById('fastleo-menu').getBoundingClientRect().right <= 0")
        ->press('Открыть меню')
        ->wait(0.4)
        ->assertScript("document.getElementById('fastleo-menu').getBoundingClientRect().left === 0")
        ->press('Закрыть меню')
        ->wait(0.4)
        ->assertScript("document.getElementById('fastleo-menu').getBoundingClientRect().right <= 0");
});

it('asks for the one-time code after the password and signs in with it', function (): void {
    $secret = withTwoFactor(User::query()->where('email', 'admin@example.com')->firstOrFail())['secret'];

    $page = visit(route('fastleo.login'));

    $page->fill('email', 'admin@example.com')
        ->fill('password', 'password')
        ->pressAndWaitFor('Войти') // поле кода оживает с Alpine: ввод до его запуска затирается
        ->assertSee('Код подтверждения')
        ->fill('code', '000000')
        ->pressAndWaitFor('Подтвердить')
        ->assertSee('Неверный или уже использованный код.')
        ->fill('code', totp($secret))
        ->pressAndWaitFor('Подтвердить')
        ->assertSee('Здравствуйте, Иван!')
        ->assertNoJavaScriptErrors();
});

it('shows the one-time code in six cells and switches to a backup code', function (): void {
    withTwoFactor(User::query()->where('email', 'admin@example.com')->firstOrFail());

    $page = visit(route('fastleo.login'));

    $page->fill('email', 'admin@example.com')
        ->fill('password', 'password')
        ->pressAndWaitFor('Войти') // поле кода оживает с Alpine: ввод до его запуска затирается
        ->type('#two-factor-code', '12ab34')
        ->assertScript("[...document.querySelectorAll('[data-code-cell]')].map((cell) => cell.textContent.trim()).join('') === '1234'")
        ->press('Резервный код')
        ->assertVisible('input[name=code]:not(#two-factor-code)')
        ->assertNoJavaScriptErrors();
});

it('shows the profile with devices and the two-factor setup', function (): void {
    $page = signInAndVisit(route('fastleo.profile.show'));

    $page->assertSee('Где вы вошли')
        ->assertSee('Это устройство')
        ->fill('[data-two-factor-enable] [name="current_password"]', 'password')
        ->press('Включить')
        ->assertPresent('[data-two-factor-setup] svg')
        ->assertNoJavaScriptErrors();
});

it('turns on two-factor protection with a code from the app and shows the backup codes', function (): void {
    $page = signInAndVisit(route('fastleo.profile.show'));

    $page->fill('[data-two-factor-enable] [name="current_password"]', 'password')
        ->press('Включить')
        ->assertPresent('[data-two-factor-secret]');
    $secret = str_replace(' ', '', (string) $page->script("document.querySelector('[data-two-factor-secret]').textContent"));

    $page->fill('[data-two-factor-setup] [name="current_password"]', 'password')
        ->fill('[data-two-factor-setup] [name="code"]', totp($secret))
        ->press('Подтвердить и включить')
        ->assertPresent('[data-recovery-codes]')
        ->assertSee('Резервные коды')
        ->assertNoJavaScriptErrors();

    expect(TwoFactorCredential::query()->sole()->confirmed_at)->not->toBeNull();
});
