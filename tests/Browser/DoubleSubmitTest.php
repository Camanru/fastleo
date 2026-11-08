<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

// Форма уходит в скрытый iframe: считаются отправки, которые не отменены (отменённые повторные в счёт не идут).
it('sends a form once however many times it is submitted while the answer is awaited', function (): void {
    $page = signInAndVisit(route('fastleo.dashboard'));
    // Скрипты страницы подключаются как модули и работают до события load: пока страница не загрузилась, защиты от повторной отправки ещё нет.
    waitUntil($page, "document.readyState === 'complete'");

    $page->script(<<<'JS'
        window.__sent = 0;
        document.addEventListener('submit', (event) => (window.__sent += event.defaultPrevented ? 0 : 1));
        const sink = document.createElement('iframe');
        sink.name = 'sink';
        document.body.append(sink);
        const form = document.createElement('form');
        form.method = 'post';
        form.action = '/fastleo/nowhere';
        form.target = 'sink';
        form.id = 'double';
        document.body.append(form);
        const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
        (async () => {
            form.requestSubmit();
            await tick();
            form.requestSubmit();
            await tick();
            form.requestSubmit();
        })();
    JS);

    $page->wait(0.5)
        ->assertScript('window.__sent', 1)
        ->assertScript("document.querySelector('#double').hasAttribute('data-sent')", true)
        ->assertNoJavaScriptErrors();
});

it('does not hold a form that was cancelled by another handler and a dialog form', function (): void {
    $page = signInAndVisit(route('fastleo.dashboard'));
    // Скрипты страницы подключаются как модули и работают до события load: пока страница не загрузилась, защиты от повторной отправки ещё нет.
    waitUntil($page, "document.readyState === 'complete'");

    $page->script(<<<'JS'
        window.__sent = 0;
        document.addEventListener('submit', (event) => (window.__sent += event.defaultPrevented ? 0 : 1));
        const cancelled = document.createElement('form');
        cancelled.id = 'cancelled';
        cancelled.addEventListener('submit', (event) => event.preventDefault());
        document.body.append(cancelled);
        const dialogForm = document.createElement('form');
        dialogForm.method = 'dialog';
        dialogForm.id = 'dialog-form';
        dialogForm.target = 'sink';
        document.body.append(dialogForm);
        const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
        (async () => {
            cancelled.requestSubmit();
            await tick();
            dialogForm.requestSubmit();
            await tick();
            dialogForm.requestSubmit();
        })();
    JS);

    $page->wait(0.5)
        ->assertScript("document.querySelector('#cancelled').hasAttribute('data-sent')", false)
        ->assertScript("document.querySelector('#dialog-form').hasAttribute('data-sent')", false)
        ->assertScript('window.__sent', 2)
        ->assertNoJavaScriptErrors();
});
