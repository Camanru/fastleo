<?php

declare(strict_types=1);

use Fastleo\Notifications\FastleoMessage;
use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\User;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('shows the unread count, lists notifications in the bell and marks them as read', function (): void {
    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
    Fastleo\Fastleo::notify($admin, FastleoMessage::make('Новая заявка')->body('Иван')->url('/fastleo/profile'));
    Fastleo\Fastleo::notify($admin, 'Вторая');

    $page = signInAndVisit(route('fastleo.dashboard'));

    $page->assertSeeIn('[data-bell-count]', '2')
        ->click('[data-bell] > button')
        ->assertSee('Новая заявка')
        ->assertSee('Иван')
        ->assertSee('Вторая')
        ->click('[data-bell-list] li:nth-child(2) a')
        ->assertPathIs('/fastleo/profile')
        ->assertSeeIn('[data-bell-count]', '1')
        ->assertNoJavaScriptErrors();
});

it('marks everything as read', function (): void {
    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
    Fastleo\Fastleo::notify($admin, 'Раз');

    $page = signInAndVisit(route('fastleo.dashboard'));

    $page->click('[data-bell] > button')
        ->click('Прочитать все')
        ->assertNotPresent('[data-bell-count]:not([style*="display: none"])')
        ->assertNoJavaScriptErrors();
});
