<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
    useFixtureResources('Poll');
    $this->first = Article::factory()->create(['title' => 'Первая статья', 'views' => 10]);
});

it('shows a record added by someone else without reloading the page', function (): void {
    $page = signInAndVisit(route('fastleo.resources.index', 'poll-articles'));

    $page->assertSee('Первая статья')->assertDontSee('Вторая статья');

    Article::factory()->create(['title' => 'Вторая статья']);

    $page->wait(7)
        ->assertSee('Вторая статья')
        ->assertSee('Первая статья')
        ->assertUrlIs(route('fastleo.resources.index', 'poll-articles'))
        ->assertNoJavaScriptErrors();
});

it('does not replace the list while a cell is being edited', function (): void {
    $page = signInAndVisit(route('fastleo.resources.index', 'poll-articles'));
    $views = 'tbody [data-inline="views"] input';

    $page->click($views)->fill($views, '55');

    Article::factory()->create(['title' => 'Вторая статья']);

    $page->wait(7)
        ->assertValue($views, '55')
        ->assertDontSee('Вторая статья')
        ->assertNoJavaScriptErrors();
});

it('refreshes a table of related records on the page of a record', function (): void {
    $category = Category::factory()->create();
    Article::query()->whereKey($this->first->id)->update(['category_id' => $category->id]);

    $page = signInAndVisit(route('fastleo.resources.show', ['categories', $category->id]));

    $page->assertSee('Первая статья')->assertDontSee('Вторая статья');

    Article::factory()->create(['title' => 'Вторая статья', 'category_id' => $category->id]);

    $page->wait(7)
        ->assertSee('Вторая статья')
        ->assertNoJavaScriptErrors();
});
