<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('creates a record and saves changes with Ctrl+S', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'categories'));

    $page->fill('title', 'Новости')
        ->pressAndWaitFor('Сохранить') // Ctrl+S ловит скрипт админки: нажатие до его запуска на новой странице теряется
        ->assertSee('Сохранено.')
        ->fill('title', 'Статьи')
        ->keys('#field-title', 'Control+s')
        ->wait(1) // «Сохранено.» уже есть на странице после первого сохранения — ждём ответ на Ctrl+S
        ->assertSee('Сохранено.')
        ->assertValue('title', 'Статьи')
        ->assertNoJavaScriptErrors();

    expect(Category::query()->sole()->title)->toBe('Статьи');
});

it('warns about unsaved changes only after editing', function (): void {
    $category = Category::factory()->create();
    $page = signInAndVisit(route('fastleo.resources.edit', ['categories', $category->id]));
    $leaving = "(() => { const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented; })()";

    $page->assertScript($leaving, false)
        ->type('title', ' изменено')
        ->assertScript($leaving, true);
});

it('asks in its own window before deleting', function (): void {
    $category = Category::factory()->create(['title' => 'Удалить меня']);
    $page = signInAndVisit(route('fastleo.resources.edit', ['categories', $category->id]));

    $page->click('form[data-confirm] button[type=submit]')
        ->assertScript("document.getElementById('fastleo-confirm').open")
        ->assertSee('Удалить запись навсегда? Это нельзя отменить.')
        ->click('#fastleo-confirm button[value=cancel]')
        ->assertScript("document.getElementById('fastleo-confirm').open", false);
    expect(Category::query()->count())->toBe(1);

    $page->click('form[data-confirm] button[type=submit]')
        ->click('[data-confirm-ok]')
        ->assertSee('Запись удалена.');
    expect(Category::query()->count())->toBe(0);
});

it('switches tabs and opens the tab with an error', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'articles'));

    $page->assertVisible('#field-title')
        ->click('[role="tablist"].overflow-x-auto [role="tab"]:nth-child(5)')
        ->assertVisible('#field-author_email')
        ->fill('author_email', 'не почта')
        ->click('[role="tablist"].overflow-x-auto [role="tab"]:nth-child(1)')
        ->fill('title', 'Статья')
        ->select('status', 'draft')
        ->press('Сохранить')
        ->assertSee('Поле «Email автора» должно быть адресом электронной почты.')
        ->assertVisible('#field-author_email')
        ->assertNoJavaScriptErrors();
});

it('shows cards instead of the table on a phone', function (): void {
    Article::factory()->create(['title' => 'Статья на телефоне']);
    $page = signInAndVisit(route('fastleo.resources.index', 'articles'))->resize(390, 844);

    $page->assertVisible('[data-cards]')
        ->assertScript("getComputedStyle(document.querySelector('table').closest('div.hidden')).display === 'none'")
        ->assertSee('Статья на телефоне');
});

it('moves a record to the trash and restores it from there', function (): void {
    $article = Article::factory()->create(['title' => 'В корзину']);
    $page = signInAndVisit(route('fastleo.resources.index', 'articles'));

    $page->click('table form[action="'.route('fastleo.resources.destroy', ['articles', $article->id]).'"] button[type=submit]')
        ->click('[data-confirm-ok]')
        ->assertSee('Запись перемещена в корзину.')
        ->click('Корзина')
        ->assertPresent('[data-trash-notice]')
        ->assertSee('В корзину')
        ->click('table form[action="'.route('fastleo.resources.restore', ['articles', $article->id]).'"] button[type=submit]')
        ->assertSee('Запись восстановлена.')
        ->assertNoJavaScriptErrors();

    expect($article->refresh()->trashed())->toBeFalse();
});

it('edits and saves a record on a phone', function (): void {
    $category = Category::factory()->create(['title' => 'Было']);
    $page = signInAndVisit(route('fastleo.resources.edit', ['categories', $category->id]))->resize(390, 844);

    $page->fill('title', 'Стало')
        ->press('Сохранить')
        ->assertSee('Сохранено.')
        ->assertNoJavaScriptErrors();

    expect($category->refresh()->title)->toBe('Стало');
});
