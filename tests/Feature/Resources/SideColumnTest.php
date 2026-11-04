<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Policies\HiddenArticlePolicy;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('Aside');
    $this->actingAs(admin(), Fastleo::GUARD);
    $this->category = Category::factory()->create(['title' => 'Вентиляция']);
});

/**
 * Адрес страницы просмотра рубрики.
 */
function showCategory(Category $category): string
{
    return route('fastleo.resources.show', ['categories', $category->id]);
}

describe('the second column of a record page', function (): void {
    it('holds the tables marked aside(), the other tables stay under the form', function (): void {
        $this->get(showCategory($this->category))
            ->assertOk()
            ->assertSee('data-card-aside', false)
            ->assertSeeInOrder(['data-related="children"', 'data-card-aside', 'data-related="articles"'], false);
    });

    it('is the same on the edit page, but not on the create page', function (): void {
        $this->get(route('fastleo.resources.edit', ['categories', $this->category->id]))
            ->assertOk()
            ->assertSeeInOrder(['data-related="children"', 'data-card-aside', 'data-related="articles"'], false);

        $this->get(route('fastleo.resources.create', 'categories'))->assertOk()->assertDontSee('data-card-aside', false);
    });

    it('is not there when nothing is placed in it', function (): void {
        useFixtureResources('Related');

        $this->get(showCategory($this->category))
            ->assertOk()
            ->assertDontSee('data-card-aside', false)
            ->assertDontSee('lg:grid-cols-[minmax(0,1fr)_24rem]', false);
    });

    it('does not show a table of a section that the admin may not open', function (): void {
        Gate::policy(Article::class, HiddenArticlePolicy::class);

        $this->get(showCategory($this->category))
            ->assertOk()
            ->assertSee('data-related="children"', false)
            ->assertDontSee('data-related="articles"', false)
            ->assertDontSee('data-card-aside', false);
    });
});
