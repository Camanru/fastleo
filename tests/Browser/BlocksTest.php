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

it('inserts a block right after the block whose plus button was pressed', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'site-pages'));

    $page->fill('input[name="title[ru]"]', 'Вставка между блоками')
        ->click('[data-blocks=content] [data-block-add]')
        ->click('[data-block-type=quote]')
        ->fill('[data-blocks=content] [data-block]:nth-child(1) textarea', 'Первая')
        ->click('[data-blocks=content] [data-block-add]')
        ->click('[data-block-type=quote]')
        ->fill('[data-blocks=content] [data-block]:nth-child(2) textarea', 'Третья')
        ->click('[data-blocks=content] [data-block]:nth-child(1) [data-block-insert]')
        ->click('[data-block-type=quote]')
        ->fill('[data-blocks=content] [data-block]:nth-child(2) textarea', 'Вторая')
        ->press('Сохранить')
        ->assertSee('Сохранено')
        ->assertNoJavaScriptErrors();

    expect(array_column(Page::query()->firstOrFail()->content, 'data'))->toBe([
        ['text' => 'Первая', 'author' => null],
        ['text' => 'Вторая', 'author' => null],
        ['text' => 'Третья', 'author' => null],
    ]);
});

it('moves the focus into the type menu opened from a plus button, closes it with Escape and closes it when its block is removed', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'site-pages'));

    $page->fill('input[name="title[ru]"]', 'Меню у плюса')
        ->click('[data-blocks=content] [data-block-add]')
        ->click('[data-block-type=quote]')
        ->fill('[data-blocks=content] [data-block]:nth-child(1) textarea', 'Первая')
        ->click('[data-blocks=content] [data-block-add]')
        ->click('[data-block-type=quote]')
        ->fill('[data-blocks=content] [data-block]:nth-child(2) textarea', 'Вторая')
        ->click('[data-blocks=content] [data-block]:nth-child(1) [data-block-insert]')
        ->assertVisible('[data-blocks=content] [role=menu]')
        ->assertScript("document.activeElement.getAttribute('role')", 'menuitem')
        ->keys('[data-blocks=content] [role=menu] [data-block-type=quote]', 'Escape')
        ->assertMissing('[data-blocks=content] [role=menu]')
        ->assertScript("document.activeElement.matches('[data-block]:nth-child(1) [data-block-insert]')", true)
        ->click('[data-blocks=content] [data-block]:nth-child(1) [data-block-insert]')
        ->click('[data-blocks=content] [data-block]:nth-child(1) [data-block-remove]')
        ->assertMissing('[data-blocks=content] [role=menu]')
        ->click('[data-blocks=content] [data-block-add]')
        ->click('[data-block-type=quote]')
        ->fill('[data-blocks=content] [data-block]:nth-child(2) textarea', 'Новая')
        ->press('Сохранить')
        ->assertSee('Сохранено')
        ->assertNoJavaScriptErrors();

    expect(array_column(array_column(Page::query()->firstOrFail()->content, 'data'), 'text'))->toBe(['Вторая', 'Новая']);
});

it('keeps every button of a block header on a phone screen, gives the plus button the name of its block and moves the open menu on a turn of the screen', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'site-pages'))->resize(320, 700);

    $page->fill('input[name="title[ru]"]', 'Узкий экран')
        ->click('[data-blocks=content] [data-block-add]')
        ->click('[data-block-type=quote]')
        ->fill('[data-blocks=content] [data-block]:nth-child(1) textarea', 'Цитата')
        ->script("document.querySelector('[data-block-label]').textContent = 'Клиенты и партнёры, длинная подпись типа'");

    $page->assertScript("(() => { const card = document.querySelector('[data-block]').getBoundingClientRect(); const last = document.querySelector('[data-block] [data-block-remove]').getBoundingClientRect(); return last.right <= card.right + 0.5 && last.left >= card.left; })()", true)
        ->assertScript("document.querySelector('[data-block-insert]').getAttribute('aria-label').startsWith('Добавить блок после этого: ')", true)
        ->click('[data-blocks=content] [data-block]:nth-child(1) [data-block-insert]')
        ->assertScript("(() => { const menu = document.querySelector('[data-blocks=content] [role=menu]').getBoundingClientRect(); return menu.left >= 0 && menu.right <= window.innerWidth; })()", true)
        ->wait(0.3)
        // Заведомо неверные координаты меню и поворот экрана: меню должно встать на место (событие resize, а не только прокрутка).
        ->script("Alpine.\$data(document.querySelector('[data-blocks=content]')).style = 'top: 1px; right: 999px'; window.dispatchEvent(new Event('resize'));");

    // Alpine обновляет DOM после текущей задачи: даём ему время, иначе проверка прошла бы и без обработчика.
    $page->wait(0.3)
        ->assertScript("!document.querySelector('[data-blocks=content] [role=menu]').getAttribute('style').includes('999px')", true)
        ->assertScript("(() => { const menu = document.querySelector('[data-blocks=content] [role=menu]').getBoundingClientRect(); return menu.left >= 0 && menu.right <= window.innerWidth; })()", true)
        ->assertNoJavaScriptErrors();
});

it('closes the open type menu when another block is removed, so that it does not hang at the old place', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'site-pages'));

    $page->fill('input[name="title[ru]"]', 'Удаление при меню')
        ->click('[data-blocks=content] [data-block-add]')
        ->click('[data-block-type=quote]')
        ->click('[data-blocks=content] [data-block-add]')
        ->click('[data-block-type=quote]')
        ->click('[data-blocks=content] [data-block]:nth-child(1) [data-block-insert]')
        ->assertVisible('[data-blocks=content] [role=menu]')
        ->click('[data-blocks=content] [data-block]:nth-child(2) [data-block-remove]')
        ->assertMissing('[data-blocks=content] [role=menu]')
        ->assertNoJavaScriptErrors();
});
