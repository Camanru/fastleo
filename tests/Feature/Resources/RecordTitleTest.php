<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\RecordTitle\FormOnlyResource;
use Workbench\App\Models\Category;
use Workbench\App\Models\Page;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

describe('title() of a resource', function (): void {
    beforeEach(function (): void {
        useFixtureResources('Titles');
    });

    it('titles the form, the page of a record, the search and the branch of the tree', function (): void {
        $parent = Category::factory()->create(['title' => 'Каталог']);
        $child = Category::factory()->create(['title' => 'Вентиляция', 'parent_id' => $parent->id]);

        $this->get(route('fastleo.resources.edit', ['categories', $child->id]))
            ->assertOk()
            ->assertSee('Раздел «Вентиляция»');
        $this->get(route('fastleo.resources.show', ['categories', $child->id]))
            ->assertOk()
            ->assertSee('Раздел «Вентиляция»');
        $this->get(route('fastleo.resources.index', ['resource' => 'categories', 'parent' => $parent->id]))
            ->assertOk()
            ->assertSee('Ветка: Раздел «Каталог»');
        expect($this->getJson(route('fastleo.search', ['q' => 'Вентиляц']))->assertOk()->json('groups.0.items.0.title'))->toBe('Раздел «Вентиляция»');
    });

    it('shows the key in the search when the list has only an empty relation', function (): void {
        $category = Category::factory()->create(['title' => 'Вентиляция', 'parent_id' => null]);

        $groups = collect($this->getJson(route('fastleo.search', ['q' => 'Вентиляц']))->assertOk()->json('groups'));

        expect($groups->firstWhere('label', 'Только связь')['items'][0]['title'])->toBe((string) $category->id);
    });

    it('takes the first column that is not a relation as plain text when title() is not overridden', function (): void {
        $parent = Category::factory()->create(['title' => 'Каталог']);
        $child = Category::factory()->create(['title' => 'Вентиляция', 'parent_id' => $parent->id]);
        $resource = Fastleo::resources()->find('plain-categories');

        // Первый столбец — связь с родителем, но заголовок записи — её собственное название.
        expect($resource->title($child))->toBe('Вентиляция')
            ->and($resource->title($child))->not->toContain('<')
            ->and(collect($this->getJson(route('fastleo.search', ['q' => 'Вентиляц']))->assertOk()->json('groups'))->firstWhere('label', 'Простые категории')['items'][0]['title'])->toBe('Вентиляция');
    });
});

it('titles a record by the first language of a multilingual first column, not by its JSON', function (): void {
    useFixtureResources('Translations');
    config(['fastleo.locales' => ['ru', 'en']]);
    // Порядок ключей в JSON не важен: MySQL переставляет их сам, первый язык берётся из fastleo.locales.
    $page = Page::query()->create(['title' => ['en' => 'About', 'ru' => 'О компании'], 'slug' => 'about']);

    $resource = Fastleo::resources()->find('site-pages');

    expect($resource->title($page))->toBe('О компании');
    $this->get(route('fastleo.resources.edit', ['site-pages', $page->id]))->assertOk()->assertSee('О компании')->assertDontSee('{"ru"', false);
});

it('gives an empty title to a record of a section that has no column in the list', function (): void {
    $category = Category::factory()->create(['title' => 'Вентиляция']);

    expect(new FormOnlyResource()->title($category))->toBe('');
});
