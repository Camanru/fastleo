<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('saves the settings form, adds a repeater row and shows the values on the site', function (): void {
    $page = signInAndVisit(route('fastleo.settings.edit', 'contacts'));

    $page->assertSee('Контакты')
        ->fill('phone', '+7 111 222-33-44')
        ->fill('lead_email', 'lead@example.com')
        ->click('[data-blocks=socials] [data-block-add]')
        ->fill('[data-blocks=socials] input[name$="[title]"]', 'Telegram')
        ->fill('[data-blocks=socials] input[name$="[url]"]', 'https://t.me/clinic')
        ->press('Сохранить')
        ->assertSee('Настройки сохранены.')
        ->assertValue('phone', '+7 111 222-33-44')
        ->assertNoJavaScriptErrors();

    visit(route('contacts'))
        ->assertSee('+7 111 222-33-44')
        ->assertSee('lead@example.com')
        ->assertSee('Telegram');
});

it('shows the error journal and a custom page', function (): void {
    $page = signInAndVisit(route('fastleo.logs.index'));
    $page->assertSee('Журнал ошибок')->assertNoJavaScriptErrors();

    visit(route('fastleo.pages.show', 'report'))->assertSee('Статьи по статусам')->assertNoJavaScriptErrors();
});
