<?php

declare(strict_types=1);

use Fastleo\Fields\Slug;
use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('fills the slug from the title until it is edited by hand', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'articles'));

    $page->type('title', 'О компании')
        ->assertValue('slug', 'o-kompanii')
        ->clear('slug')
        ->type('slug', 'about')
        ->type('title', ' и команде')
        ->assertValue('slug', 'about')
        ->assertNoJavaScriptErrors();
});

it('builds the same address in the form as the server does', function (string $title): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'articles'));

    $page->type('title', $title)
        ->assertValue('slug', Slug::slugify($title))
        ->assertNoJavaScriptErrors();
})->with(['2+2', 'т.е. так', 'Ёлка_и @ ёж', 'Цена — 5 000 руб', 'Café  crème']);

it('adds and removes tags and saves them', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'articles'));

    $page->fill('title', 'Статья')
        ->select('status', 'draft')
        ->type('#field-keywords', 'акция')
        ->keys('#field-keywords', 'Enter')
        ->type('#field-keywords', 'обзор,')
        ->type('#field-keywords', 'лишняя')
        ->keys('#field-keywords', 'Enter')
        ->click('[aria-label="Убрать метку лишняя"]')
        ->press('Сохранить')
        ->assertSee('Сохранено.')
        ->assertNoJavaScriptErrors();

    expect(Article::query()->sole()->keywords)->toBe(['акция', 'обзор']);
});

it('previews markdown and indents code with Tab', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'articles'));

    $page->click('[role=tab]:has-text("Содержание")')
        ->fill('body', "# Заголовок\n\n<b>не HTML</b>")
        ->click('button:has-text("Просмотр")')
        ->assertSeeIn('[data-markdown-preview]', 'Заголовок')
        ->assertSeeIn('[data-markdown-preview]', '<b>не HTML</b>')
        ->assertScript("document.querySelector('[data-markdown-preview] h1')?.textContent", 'Заголовок')
        ->click('#field-head_code')
        ->keys('#field-head_code', 'Tab')
        ->assertValue('head_code', '    ')
        ->assertNoJavaScriptErrors();
});
