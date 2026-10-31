<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
    useFixtureResources('Related');
    $this->category = Category::factory()->create(['title' => 'Вентиляция']);

    foreach (['Альфа', 'Бета', 'Гамма'] as $title) {
        Article::factory()->create(['category_id' => $this->category->id, 'title' => $title, 'status' => 'draft']);
    }
});

it('loads the table under the form and pages, searches and sorts it without leaving the page', function (): void {
    $page = signInAndVisit(route('fastleo.resources.show', ['categories', $this->category->id]));
    $table = '[data-related="articles"]';

    $page->assertSeeIn($table, 'Статьи рубрики')
        ->assertSeeIn($table, 'Гамма')
        ->assertSeeIn($table, 'Бета')
        ->assertDontSeeIn($table, 'Альфа')
        ->click($table.' nav a[aria-label="Страница 2"]')
        ->waitForText('Альфа')
        ->assertDontSeeIn($table, 'Гамма')
        ->fill($table.' input[type=search]', 'Гам')
        ->keys($table.' input[type=search]', 'Enter')
        ->waitForText('Гамма')
        ->assertDontSeeIn($table, 'Бета')
        ->assertUrlIs(route('fastleo.resources.show', ['categories', $this->category->id]))
        ->assertNoJavaScriptErrors();
});

it('changes the size of the page of the table without leaving the page', function (): void {
    foreach (range(1, 27) as $number) {
        Article::factory()->create(['category_id' => $this->category->id, 'title' => 'Запись '.$number, 'status' => 'draft']);
    }

    $page = signInAndVisit(route('fastleo.resources.show', ['categories', $this->category->id]));
    $table = '[data-related="articles"]';

    $page->assertSeeIn($table, 'Запись 27')
        ->assertDontSeeIn($table, 'Запись 3')
        ->select($table.' select[data-per-page]', '25')
        ->waitForText('Запись 3')
        ->assertUrlIs(route('fastleo.resources.show', ['categories', $this->category->id]))
        ->assertNoJavaScriptErrors();
});

it('opens the action of a row and returns to the page of the parent', function (): void {
    Article::query()->where('title', 'Гамма')->update(['is_featured' => false]);

    $page = signInAndVisit(route('fastleo.resources.show', ['categories', $this->category->id]));
    $table = '[data-related="articles"]';

    $page->assertSeeIn($table, 'Гамма')
        ->click($table.' tbody tr:first-child button[title="Действия"]')
        ->click($table.' tbody tr:first-child [role=menuitem]:first-child')
        ->waitForText('Изменено: 1.')
        ->assertUrlIs(route('fastleo.resources.show', ['categories', $this->category->id]))
        ->assertNoJavaScriptErrors();

    expect(Article::query()->where('title', 'Гамма')->value('is_featured'))->toBeTruthy();
});

it('opens the window of an action form again with the error when the check fails', function (): void {
    $page = signInAndVisit(route('fastleo.resources.show', ['categories', $this->category->id]));
    $table = '[data-related="articles"]';

    $page->assertSeeIn($table, 'Гамма')
        ->click($table.' tbody tr:first-child button[title="Действия"]')
        ->click($table.' tbody tr:first-child [role=menuitem]:nth-child(2)')
        ->fill('#fastleo-action-stamp-articles input[name="data[code]"]', '0000')
        ->click('#fastleo-action-stamp-articles button[type=submit]')
        ->waitForText('Выбрано недопустимое значение')
        ->assertValue('#fastleo-action-stamp-articles input[name="data[code]"]', '0000')
        ->assertUrlIs(route('fastleo.resources.show', ['categories', $this->category->id]))
        ->assertNoJavaScriptErrors();
});

it('does not widen the page when the table is wider than the window', function (): void {
    Article::factory()->create(['category_id' => $this->category->id, 'title' => str_repeat('Длинное название ', 6), 'status' => 'draft']);

    $page = signInAndVisit(route('fastleo.resources.show', ['categories', $this->category->id]));
    $page->resize(440, 700)->assertSeeIn('[data-related="articles"]', 'Длинное название');

    $page->assertScript('document.documentElement.scrollWidth - document.documentElement.clientWidth', 0);
});
