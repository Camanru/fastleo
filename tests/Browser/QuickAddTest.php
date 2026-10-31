<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
    useFixtureResources('QuickAdd');
    $this->category = Category::factory()->create(['title' => 'Вентиляция']);
});

it('adds a record from the small form under the table without leaving the page and clears the form', function (): void {
    $page = signInAndVisit(route('fastleo.resources.show', ['categories', $this->category->id]));
    $form = '[data-related="articles"] [data-quick-add]';

    $page->waitForText('Записей пока нет.')
        ->fill($form.' input[name="data[title]"]', 'Решётка вентиляционная')
        ->click($form.' button[type="submit"]')
        ->waitForText('Решётка вентиляционная')
        ->assertScript("document.querySelector('{$form} input[name=\"data[title]\"]').value", '')
        ->assertScript('location.pathname', '/fastleo/categories/'.$this->category->id)
        ->assertNoJavaScriptErrors();

    expect(Article::query()->where('category_id', $this->category->id)->pluck('title')->all())->toBe(['Решётка вентиляционная']);
});

it('shows the errors of the check under the fields and keeps what was typed', function (): void {
    $page = signInAndVisit(route('fastleo.resources.show', ['categories', $this->category->id]));
    $form = '[data-related="articles"] [data-quick-add]';

    $page->waitForText('Добавить статью')
        ->fill($form.' input[name="data[views]"]', '-4')
        ->click($form.' button[type="submit"]')
        ->waitForText('должно быть не меньше 0')
        ->assertScript("document.querySelector('{$form} input[name=\"data[views]\"]').value", '-4')
        ->assertNoJavaScriptErrors();

    expect(Article::query()->count())->toBe(0);
});
