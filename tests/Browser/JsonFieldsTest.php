<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Page;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('adds repeater items and key value rows and saves them', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'site-pages'));

    $page->fill('input[name="title[ru]"]', 'О клинике')
        ->click('[data-blocks=faq] [data-block-add]')
        ->click('[data-blocks=faq] [data-block-add]')
        ->fill('[data-blocks=faq] [data-block]:nth-child(1) input[name$="[question]"]', 'Первый')
        ->fill('[data-blocks=faq] [data-block]:nth-child(2) input[name$="[question]"]', 'Второй')
        ->click('[data-blocks=specs] [data-block-add]')
        ->fill('[data-blocks=specs] input[name$="[key]"]', 'Площадь')
        ->fill('[data-blocks=specs] input[name$="[value]"]', '120 м²')
        ->press('Сохранить')
        ->assertSee('Сохранено')
        ->assertNoJavaScriptErrors();

    $saved = Page::query()->firstOrFail();

    expect(array_column($saved->faq ?? [], 'question'))->toBe(['Первый', 'Второй'])
        ->and($saved->specs)->toBe(['Площадь' => '120 м²']);
});

it('switches the language tabs of a translatable field', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'site-pages'));

    $page->assertVisible('input[name="title[ru]"]')
        ->assertMissing('input[name="title[en]"]')
        ->click('[data-translatable=title] [data-locale=en]')
        ->assertVisible('input[name="title[en]"]')
        ->fill('input[name="title[en]"]', 'About')
        ->click('[data-translatable=title] [data-locale=ru]')
        ->fill('input[name="title[ru]"]', 'О клинике')
        ->press('Сохранить')
        ->assertSee('Сохранено')
        ->assertNoJavaScriptErrors();

    expect(Page::query()->firstOrFail()->only('title', 'slug'))->toBe(['title' => ['ru' => 'О клинике', 'en' => 'About'], 'slug' => 'o-klinike']);
});
