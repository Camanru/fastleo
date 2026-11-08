<?php

declare(strict_types=1);

use Fastleo\Publishing\Publication;
use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('checks and unchecks chips by click and keyboard and reloads them for the dependency', function (): void {
    useFixtureResources('MultipleSelect');
    $checked = fn (string $value): string => "document.querySelector('input[name=\"tags[]\"][value=\"{$value}\"]').checked";

    $page = signInAndVisit(route('fastleo.resources.create', 'multiple-select'));

    $page->select('type', 'news')
        ->click('label:has(input[value="news"])')
        ->assertScript($checked('news'))
        ->click('label:has(input[value="news"])')
        ->assertScript($checked('news'), false)
        ->keys('#field-tags', 'Space')
        ->assertScript($checked('news'))
        ->select('type', 'promo')
        ->assertScript("document.querySelectorAll('input[name=\"tags[]\"]').length", 1)
        ->assertSeeIn('[data-chips]', 'Скидки')
        // После подгрузки вариантов id поля (на него ссылаются подпись и подсказка) — у первой галочки.
        ->assertScript("document.getElementById('field-tags')?.value", 'sale')
        ->click('label:has(input[value="sale"])')
        ->fill('title', 'Акция')
        ->press('Сохранить')
        ->assertSee('Сохранено.')
        ->assertNoJavaScriptErrors();

    expect(Article::query()->sole()->tags)->toBe(['sale']);
});

it('saves a publication with one of the required channels checked', function (): void {
    $channel = publishingChannel();

    $page = signInAndVisit(route('fastleo.resources.create', 'publications'));

    $page->fill('text', 'Привет')
        ->click('label:has(input[name="channel_ids[]"])')
        ->press('Сохранить')
        ->assertSee('Сохранено.')
        ->assertNoJavaScriptErrors();

    expect(Publication::query()->sole()->channel_ids)->toBe([(string) $channel->id]);
});
