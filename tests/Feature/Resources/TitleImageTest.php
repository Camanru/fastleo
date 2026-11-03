<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    useFixtureResources('TitleImage');
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('puts the cover as a thumbnail before the title instead of a column of its own', function (): void {
    Article::factory()->create(['title' => 'С обложкой', 'cover' => 'uploads/articles/a.jpg']);

    $page = $this->get(route('fastleo.resources.index', 'articles'))->assertOk();

    $page->assertSee(route('fastleo.files.thumb', ['path' => 'uploads/articles/a.jpg']), false)
        ->assertSee('data-title-thumb', false)
        ->assertSee('Вложение')
        ->assertDontSee('data-field="cover"', false)
        ->assertDontSee('>Обложка<', false);
    $page->assertSeeInOrder([route('fastleo.files.thumb', ['path' => 'uploads/articles/a.jpg']), 'С обложкой'])
        ->assertSee('onerror="this.hidden = true"', false);
});

it('keeps the rows aligned with an empty frame when the record has no picture', function (): void {
    Article::factory()->create(['title' => 'Без обложки', 'cover' => null]);
    Article::factory()->create(['title' => 'Не картинка', 'cover' => 'uploads/articles/a.pdf']);

    $page = $this->get(route('fastleo.resources.index', 'articles'))->assertOk();

    expect(substr_count($page->getContent(), '<span class="inline-block h-[30px] w-10 rounded-md bg-sunken align-middle" data-title-thumb></span>'))->toBe(4); // две записи, каждая в таблице и в карточках для телефона
});

it('leaves the picture a column when it is not placed in the title', function (): void {
    useFixtureResources('Filters');

    expect(Fastleo::resources()->find('articles')?->titleImage())->toBeNull()
        ->and(Fastleo::resources()->find('articles')?->listColumns())->toHaveCount(count(Fastleo::resources()->find('articles')?->listFields() ?? []));
});
