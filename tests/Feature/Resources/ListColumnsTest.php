<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('offers every column except the first in the column picker and marks the cells of all three views', function (): void {
    useFixtureResources('HideEmpty');
    Article::factory()->create(['title' => 'Отчёт', 'phone' => '+7 900 000-00-00', 'author_email' => 'a@b.ru', 'cover' => 'uploads/a.jpg']);

    $page = $this->get(route('fastleo.resources.index', 'articles'))->assertOk();

    $page->assertSee('data-columns-button', false)
        ->assertSee('data-column-toggle="phone"', false)
        ->assertSee('data-column-toggle="author_email"', false)
        ->assertDontSee('data-column-toggle="title"', false)
        ->assertSee('fastleo.columns.articles', false)
        ->assertSee('<th scope="col" data-column="phone"', false)
        ->assertSee('<td data-field="phone" data-column="phone"', false)
        ->assertSee('<div class="flex gap-3" data-column="phone">', false);
});

it('has no column picker when the list has a single column', function (): void {
    useFixtureResources('OneColumn');
    Article::factory()->create(['title' => 'Отчёт']);

    $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertSee('Отчёт')->assertDontSee('data-columns-button', false);
});
