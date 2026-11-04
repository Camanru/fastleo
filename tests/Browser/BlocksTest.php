<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Page;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('adds blocks with an editor, moves and removes them and saves the order', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'site-pages'));

    $page->fill('input[name="title[ru]"]', 'Конструктор')
        ->click('[data-blocks=content] [data-block-add]')
        ->click('[data-block-type=text]')
        ->assertPresent('[data-blocks=content] [data-rich-text][data-ready]')
        ->script("hugerte.activeEditor.setContent('<p>Текст блока</p>'); hugerte.activeEditor.save();");

    $page->click('[data-blocks=content] [data-block-add]')
        ->click('[data-block-type=quote]')
        ->fill('[data-blocks=content] [data-block-kind=quote] textarea', 'Цитата')
        ->click('[data-blocks=content] [data-block-add]')
        ->click('[data-block-type=quote]')
        ->click('[data-blocks=content] [data-block]:nth-child(3) [data-block-remove]')
        ->drag('[data-blocks=content] [data-block]:nth-child(2) [data-block-handle]', '[data-blocks=content] [data-block]:nth-child(1) [data-block-handle]')
        ->assertPresent('[data-blocks=content] [data-rich-text][data-ready]')
        ->press('Сохранить')
        ->assertSee('Сохранено')
        ->assertNoJavaScriptErrors();

    expect(Page::query()->firstOrFail()->content)->toBe([
        ['type' => 'quote', 'data' => ['text' => 'Цитата', 'author' => null]],
        ['type' => 'text', 'data' => ['body' => '<p>Текст блока</p>']],
    ]);
});

it('starts the editor again in a block that was moved while collapsed', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'site-pages'));

    $page->fill('input[name="title[ru]"]', 'Свёрнутые блоки')
        ->click('[data-blocks=content] [data-block-add]')
        ->click('[data-block-type=text]')
        ->click('[data-blocks=content] [data-block-add]')
        ->click('[data-block-type=text]')
        ->assertPresent('[data-blocks=content] [data-block]:nth-child(2) [data-rich-text][data-ready]')
        ->script("hugerte.get()[0].setContent('<p>Первый</p>'); hugerte.get()[1].setContent('<p>Второй</p>'); hugerte.triggerSave();");

    $page->click('[data-blocks=content] [data-block]:nth-child(1) [data-block-toggle]')
        ->click('[data-blocks=content] [data-block]:nth-child(2) [data-block-toggle]')
        ->drag('[data-blocks=content] [data-block]:nth-child(2) [data-block-handle]', '[data-blocks=content] [data-block]:nth-child(1) [data-block-handle]')
        ->click('[data-blocks=content] [data-block]:nth-child(1) [data-block-toggle]')
        ->click('[data-blocks=content] [data-block]:nth-child(2) [data-block-toggle]')
        ->assertPresent('[data-blocks=content] [data-block]:nth-child(1) [data-rich-text] iframe')
        ->assertPresent('[data-blocks=content] [data-block]:nth-child(2) [data-rich-text] iframe')
        ->press('Сохранить')
        ->assertSee('Сохранено')
        ->assertNoJavaScriptErrors();

    expect(Page::query()->firstOrFail()->content)->toBe([
        ['type' => 'text', 'data' => ['body' => '<p>Второй</p>']],
        ['type' => 'text', 'data' => ['body' => '<p>Первый</p>']],
    ]);
});
