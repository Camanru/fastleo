<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
    Http::preventStrayRequests();
});

it('shows the section on a phone without sideways scrolling of the page', function (): void {
    connectSoftNeticaWith();

    $page = signInAndVisit(route('fastleo.pages.show', 'softnetica'))->resize(390, 844);

    $page->assertSee('Основная копия')
        ->assertSee('Счёт № 1')
        ->assertPresent('[data-softnetica-block="invoices"]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();
});

it('asks before disconnecting and keeps the token on cancel', function (): void {
    connectSoftNeticaWith();

    $page = signInAndVisit(route('fastleo.settings.edit', 'softnetica'));

    $page->assertPresent('[data-softnetica-state]')
        ->click('[data-softnetica-disconnect]')
        ->assertScript("document.getElementById('fastleo-confirm').open")
        ->assertSeeIn('#fastleo-confirm', 'Отключить сайт от SoftNetica?')
        ->click('#fastleo-confirm button[value=cancel]')
        ->assertScript("document.getElementById('fastleo-confirm').open", false)
        ->assertPresent('[data-softnetica-disconnect]')
        ->assertNoJavaScriptErrors();
});
