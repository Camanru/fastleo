<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Page;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    useFixtureResources('Nested');
    admin(['email' => 'admin@example.com']);
});

/**
 * Элемент внешнего повторителя `faq` под номером $n.
 */
function faqItem(int $n): string
{
    return "[data-blocks=faq] > [x-ref=list] > [data-block]:nth-child($n)";
}

/**
 * Шаг $m внутри элемента $n.
 */
function faqStep(int $n, int $m): string
{
    return faqItem($n).' [data-blocks$=".steps"] > [x-ref=list] > [data-block]:nth-child('.$m.')';
}

it('adds and saves items of a list inside the items of another list', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'site-pages'));

    $page->fill('input[name="title[ru]"]', 'О клинике')
        ->click('[data-blocks=faq] > div > [data-block-add]')
        ->click('[data-blocks=faq] > div > [data-block-add]')
        ->fill(faqItem(1).' input[name$="[question]"]', 'Первый')
        ->fill(faqItem(2).' input[name$="[question]"]', 'Второй')
        ->click(faqItem(1).' [data-blocks$=".steps"] > div > [data-block-add]')
        ->click(faqItem(1).' [data-blocks$=".steps"] > div > [data-block-add]')
        ->click(faqItem(2).' [data-blocks$=".steps"] > div > [data-block-add]')
        ->fill(faqStep(1, 1).' input[name$="[title]"]', 'Шаг А')
        ->fill(faqStep(1, 2).' input[name$="[title]"]', 'Шаг Б')
        ->fill(faqStep(2, 1).' input[name$="[title]"]', 'Шаг В')
        ->press('Сохранить')
        ->assertSee('Сохранено')
        ->assertNoJavaScriptErrors();

    $faq = Page::query()->firstOrFail()->faq ?? [];

    expect(array_column($faq, 'question'))->toBe(['Первый', 'Второй'])
        ->and(array_column($faq[0]['steps'], 'title'))->toBe(['Шаг А', 'Шаг Б'])
        ->and(array_column($faq[1]['steps'], 'title'))->toBe(['Шаг В']);
});

it('moves an inner item without moving the outer one', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'site-pages'));

    $page->fill('input[name="title[ru]"]', 'О клинике')
        ->click('[data-blocks=faq] > div > [data-block-add]')
        ->fill(faqItem(1).' input[name$="[question]"]', 'Вопрос')
        ->click(faqItem(1).' [data-blocks$=".steps"] > div > [data-block-add]')
        ->click(faqItem(1).' [data-blocks$=".steps"] > div > [data-block-add]')
        ->fill(faqStep(1, 1).' input[name$="[title]"]', 'Шаг А')
        ->fill(faqStep(1, 2).' input[name$="[title]"]', 'Шаг Б')
        ->drag(faqStep(1, 2).' [data-block-handle]', faqStep(1, 1).' [data-block-handle]')
        ->press('Сохранить')
        ->assertSee('Сохранено')
        ->assertNoJavaScriptErrors();

    $faq = Page::query()->firstOrFail()->faq ?? [];

    expect(array_column($faq, 'question'))->toBe(['Вопрос'])
        ->and(array_column($faq[0]['steps'], 'title'))->toBe(['Шаг Б', 'Шаг А']);
});

it('keeps the key of every nested item apart and removes only the item that was clicked', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'site-pages'));

    $page->fill('input[name="title[ru]"]', 'О клинике')
        ->click('[data-blocks=faq] > div > [data-block-add]')
        ->fill(faqItem(1).' input[name$="[question]"]', 'Вопрос')
        ->click(faqItem(1).' [data-blocks$=".steps"] > div > [data-block-add]')
        ->click(faqItem(1).' [data-blocks$=".steps"] > div > [data-block-add]')
        ->fill(faqStep(1, 1).' input[name$="[title]"]', 'Останется')
        ->fill(faqStep(1, 2).' input[name$="[title]"]', 'Уйдёт')
        ->click(faqStep(1, 2).' [data-block-remove]')
        ->press('Сохранить')
        ->assertSee('Сохранено')
        ->assertNoJavaScriptErrors();

    $faq = Page::query()->firstOrFail()->faq ?? [];

    expect(array_column($faq[0]['steps'], 'title'))->toBe(['Останется']);
});

it('shows a field by the value of the control of its own item, not of an inner item with the same name', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'site-pages'));

    $page->click('[data-blocks=faq] > div > [data-block-add]')
        ->click(faqItem(1).' [data-blocks$=".steps"] > div > [data-block-add]')
        ->select(faqStep(1, 1).' select[name$="[mode]"]', 'extra')
        ->assertVisible(faqStep(1, 1).' input[name$="[extra]"]')
        ->assertMissing(faqItem(1).' > div > div input[name$="[remark]"]')
        ->select(faqItem(1).' select[name^="faq"][name$="[mode]"]:not([name*="[steps]"])', 'extra')
        ->assertVisible(faqItem(1).' input[name$="[remark]"]')
        ->assertNoJavaScriptErrors();
});
