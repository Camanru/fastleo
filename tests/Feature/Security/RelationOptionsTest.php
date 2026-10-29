<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Policies\HiddenCategoryPolicy;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

it('does not give options to a guest', function (): void {
    $this->getJson(route('fastleo.resources.options', ['articles', 'category_id']))->assertUnauthorized();
});

it('does not give options without access to the section', function (): void {
    Gate::policy(Article::class, HiddenCategoryPolicy::class);
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->getJson(route('fastleo.resources.options', ['articles', 'category_id']))->assertForbidden();
});

describe('field query', function (): void {
    beforeEach(function (): void {
        useFixtureResources('Relations');
        $this->actingAs(admin(), Fastleo::GUARD);
    });

    it('does not offer a record outside the field query', function (): void {
        Category::factory()->create(['title' => 'Открытая', 'code' => 'open']);
        Category::factory()->create(['title' => 'Закрытая', 'code' => null]);

        $labels = collect($this->getJson(route('fastleo.resources.options', ['scoped-articles', 'category_id']))->json('options'))->pluck('label');

        expect($labels->all())->toBe(['Открытая']);
    });

    it('does not accept a record outside the field query sent by hand', function (): void {
        $closed = Category::factory()->create(['code' => null]);

        $this->post(route('fastleo.resources.store', 'scoped-articles'), ['title' => 'Статья', 'category_id' => (string) $closed->id])
            ->assertSessionHasErrors('category_id');

        expect(Article::query()->count())->toBe(0);
    });
});

it('escapes the search pattern and the labels', function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
    Category::factory()->create(['title' => '<script>alert(1)</script>']);
    Category::factory()->create(['title' => 'Скидки 50%']);
    Category::factory()->create(['title' => 'Скидки 500']);

    $labels = collect($this->getJson(route('fastleo.resources.options', ['articles', 'category_id']).'?q=50%25')->json('options'))->pluck('label');
    expect($labels->all())->toBe(['Скидки 50%']);

    Article::factory()->create(['category_id' => Category::query()->where('title', 'like', '<script>%')->value('id')]);
    $this->get(route('fastleo.resources.index', 'articles'))
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});

describe('dependent list with a database error', function (): void {
    beforeEach(function (): void {
        useFixtureResources('BrokenDependency');
        $this->actingAs(admin(), Fastleo::GUARD);
    });

    it('gives no options instead of failing when the dependency cannot be queried', function (): void {
        $this->getJson(route('fastleo.resources.options', ['articles', 'subcategory_id']).'?depends=abc')
            ->assertOk()
            ->assertExactJson(['options' => []]);
    });

    it('rejects a saved value instead of failing when the dependency cannot be queried', function (): void {
        $category = Category::factory()->create();

        $this->post(route('fastleo.resources.store', 'articles'), ['title' => 'Статья', 'category_id' => (string) $category->id, 'subcategory_id' => '5'])
            ->assertSessionHasErrors('subcategory_id');
    });
});
