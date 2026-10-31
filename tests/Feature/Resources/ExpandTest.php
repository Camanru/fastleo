<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Related;
use Fastleo\Tests\Fixtures\Expand\ArticleResource;
use Fastleo\Tests\Fixtures\Policies\NoViewArticlePolicy;
use Fastleo\Tests\Fixtures\Policies\NoViewLinkPolicy;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Article;
use Workbench\App\Models\ArticleLink;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('Expand');
    $this->actingAs(admin(), Fastleo::GUARD);
    $this->category = Category::factory()->create(['title' => 'Вентиляция']);
    $this->article = Article::factory()->create(['title' => 'Решётка', 'category_id' => $this->category->id]);
});

/**
 * Адрес раскрытия строки статьи.
 *
 * @param  array<string, mixed>  $query
 */
function expandUrl(Article $article, array $query = []): string
{
    return route('fastleo.resources.expand', ['articles', $article->id, ...$query]);
}

describe('the row of a table', function (): void {
    it('has an arrow and a hidden place for the nested tables when the relation is expandable', function (): void {
        $this->get(route('fastleo.resources.related', ['categories', $this->category->id, 'articles']))
            ->assertOk()
            ->assertSee('data-expand', false)
            ->assertSee('data-expansion', false)
            ->assertSee(expandUrl($this->article, ['return' => route('fastleo.resources.show', ['categories', $this->category->id])]), false);
    });

    it('has no arrow for a record that the admin may not open', function (): void {
        Gate::policy(Article::class, NoViewArticlePolicy::class);

        $this->get(route('fastleo.resources.related', ['categories', $this->category->id, 'articles']))
            ->assertOk()
            ->assertSee('Решётка')
            ->assertDontSee('data-expand', false);
    });

    it('is not expandable unless the relation says so', function (): void {
        expect(Related::make('articles', ArticleResource::class)->isExpandable())->toBeFalse()
            ->and(Related::make('articles', ArticleResource::class)->expandable()->isExpandable())->toBeTrue();
    });
});

describe('the nested tables', function (): void {
    it('are the relations of the record itself and keep the way back to the first page', function (): void {
        $page = route('fastleo.resources.show', ['categories', $this->category->id]);

        $this->get(expandUrl($this->article, ['return' => $page]))
            ->assertOk()
            ->assertSee('data-related="links"', false)
            ->assertSee(str_replace('/', '\\/', route('fastleo.resources.related', ['articles', $this->article->id, 'links', 'return' => $page])), false);
    });

    it('lead back to the record page when no way back is given', function (): void {
        $this->get(expandUrl($this->article))
            ->assertOk()
            ->assertSee(str_replace('/', '\\/', route('fastleo.resources.show', ['articles', $this->article->id])), false);
    });

    it('are drawn without the top margin of the page sections', function (): void {
        $this->get(expandUrl($this->article))->assertOk()->assertDontSee('mt-6', false);
    });

    it('are left out when the admin may not open the list of the nested section', function (): void {
        Gate::policy(ArticleLink::class, NoViewLinkPolicy::class);

        $this->get(expandUrl($this->article))->assertOk()->assertDontSee('data-related="links"', false);
    });

    it('load the next level the same way', function (): void {
        $this->article->links()->create(['title' => 'Паспорт', 'url' => 'https://example.com']);

        $this->get(route('fastleo.resources.related', ['articles', $this->article->id, 'links']))->assertOk()->assertSee('Паспорт');
    });
});

describe('refusals', function (): void {
    it('asks for a login', function (): void {
        auth(Fastleo::GUARD)->logout();

        $this->get(expandUrl($this->article))->assertRedirect(route('fastleo.login'));
    });

    it('refuses a record that the admin may not open', function (): void {
        Gate::policy(Article::class, NoViewArticlePolicy::class);

        $this->get(expandUrl($this->article))->assertForbidden();
    });

    it('answers 404 for an unknown section, a wrong key and a missing record', function (): void {
        $this->get(route('fastleo.resources.expand', ['nothing', 1]))->assertNotFound();
        $this->get(route('fastleo.resources.expand', ['articles', 'a b']))->assertNotFound();
        $this->get(route('fastleo.resources.expand', ['articles', 99999]))->assertNotFound();
    });
});

describe('the description', function (): void {
    it('refuses to expand rows that have nothing to show', function (): void {
        expect(fn (): array => Fastleo::resources()->find('bare')->listRelations())->toThrow(LogicException::class, 'expandable()');
    });
});
