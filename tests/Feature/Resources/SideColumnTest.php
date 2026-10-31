<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Policies\NoViewArticlePolicy;
use Fastleo\Tests\Fixtures\Policies\UpdateNoViewCategoryPolicy;
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
    it('holds the tables marked aside() and the history panel, the other tables stay under the form', function (): void {
        $this->get(showCategory($this->category))
            ->assertOk()
            ->assertSee('data-card-aside', false)
            ->assertSee('data-history-panel', false)
            ->assertSeeInOrder(['data-related="children"', 'data-card-aside', 'data-history-panel', 'data-related="articles"'], false);
    });

    it('is the same on the edit page, but not on the create page', function (): void {
        $this->get(route('fastleo.resources.edit', ['categories', $this->category->id]))
            ->assertOk()
            ->assertSeeInOrder(['data-related="children"', 'data-card-aside', 'data-history-panel', 'data-related="articles"'], false);

        $this->get(route('fastleo.resources.create', 'categories'))->assertOk()->assertDontSee('data-card-aside', false);
    });

    it('is not there when nothing is placed in it', function (): void {
        useFixtureResources('Related');

        $this->get(showCategory($this->category))
            ->assertOk()
            ->assertDontSee('data-card-aside', false)
            ->assertDontSee('lg:grid-cols-[minmax(0,1fr)_24rem]', false);
    });

    it('does not show a table of a section that the admin may not open, but keeps the panel', function (): void {
        Gate::policy(Article::class, NoViewArticlePolicy::class);

        $this->get(showCategory($this->category))
            ->assertOk()
            ->assertSee('data-history-panel', false)
            ->assertSee('data-related="children"', false);
    });
});

describe('the history panel', function (): void {
    it('lists the last changes: who, when and the new values, with a link to the whole history', function (): void {
        $this->put(route('fastleo.resources.update', ['categories', $this->category->id]), ['title' => 'Кондиционирование'])->assertRedirect();

        $this->get(showCategory($this->category))
            ->assertOk()
            ->assertSee('История изменений')
            ->assertSee('Название:')
            ->assertSee('Кондиционирование')
            ->assertSee('data-history-entry="updated"', false)
            ->assertSee(route('fastleo.resources.history', ['categories', $this->category->id]), false);
    });

    it('says so when nothing has changed yet', function (): void {
        $this->get(showCategory($this->category))->assertOk()->assertSee('Изменений пока нет.');
    });

    it('is closed to an admin who may not read the journal', function (): void {
        Gate::define('viewFastleoActivity', fn (): bool => false);

        $this->get(showCategory($this->category))
            ->assertOk()
            ->assertDontSee('data-history-panel', false)
            ->assertSee('data-related="articles"', false);
    });

    it('is closed on the edit page too when the admin may change the record but not look at it', function (): void {
        Gate::policy(Category::class, UpdateNoViewCategoryPolicy::class);

        $this->get(route('fastleo.resources.edit', ['categories', $this->category->id]))
            ->assertOk()
            ->assertDontSee('data-history-panel', false);
    });

    it('is off unless the resource asks for it', function (): void {
        useFixtureResources('Related');

        $this->get(showCategory($this->category))->assertOk()->assertDontSee('data-history-panel', false);
    });
});
