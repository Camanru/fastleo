<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\User;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    config(['fastleo.users.impersonate' => true]);
    Fastleo::resolveOwnerUsing(fn (User $user): bool => $user->email === 'admin@example.com');
    admin(['email' => 'admin@example.com']);
    admin(['email' => 'manager@example.com', 'name' => 'Manager']);
});

afterEach(function (): void {
    Fastleo::resolveOwnerUsing(null);
});

it('signs the owner in as a user, shows who they are and returns', function (): void {
    $page = signInAndVisit(route('fastleo.resources.index', 'users'));

    $page->click('tr:has-text("manager@example.com") [aria-haspopup=menu]')
        ->click('tr:has-text("manager@example.com") [role=menuitem]:has-text("Войти как")')
        ->click('#fastleo-confirm [data-confirm-primary]')
        ->assertPresent('[data-impersonating]')
        ->assertSeeIn('[data-impersonating]', 'Вы вошли как Manager')
        ->assertNotPresent('a[data-profile-link]')
        ->click('[data-impersonate-leave]')
        ->assertNotPresent('[data-impersonating]')
        ->assertSee('Вы вернулись в свою учётную запись.')
        ->assertNoJavaScriptErrors();
});
