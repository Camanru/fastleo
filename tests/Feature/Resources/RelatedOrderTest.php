<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Policies\ClosedListLinkPolicy;
use Fastleo\Tests\Fixtures\Policies\NoUpdateLinkPolicy;
use Fastleo\Tests\Fixtures\Policies\NoViewArticlePolicy;
use Fastleo\Tests\Fixtures\RelatedOrder\ArticleLinkResource;
use Fastleo\Tests\Fixtures\RelatedOrder\ArticleResource;
use Fastleo\Tests\Fixtures\RelatedOrder\ReadonlyLinkResource;
use Fastleo\Tests\Fixtures\RelatedOrder\TreedLinkResource;
use Fastleo\Tests\Fixtures\RelatedOrder\UnsortedLinkResource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Article;
use Workbench\App\Models\ArticleLink;

beforeEach(function (): void {
    useFixtureResources('RelatedOrder');
    $this->actingAs(admin(), Fastleo::GUARD);
    $this->article = Article::factory()->create();
    $this->other = Article::factory()->create();
    $this->reorderUrl = route('fastleo.resources.related.reorder', ['articles', $this->article->id, 'links']);
});

afterEach(function (): void {
    ArticleResource::$child = ArticleLinkResource::class;
});

/**
 * Ссылка статьи в порядке `$sort`; возвращает её ключ.
 */
function articleLink(Article $article, string $title, int $sort): int
{
    return DB::table('article_links')->insertGetId(['article_id' => $article->id, 'title' => $title, 'sort' => $sort, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * Заголовки ссылок статьи по порядку.
 *
 * @return list<string>
 */
function linkTitles(Article $article): array
{
    return DB::table('article_links')->where('article_id', $article->id)->orderBy('sort')->orderBy('id')->pluck('title')->all();
}

describe('the table', function (): void {
    it('has a handle for every record in the default view', function (): void {
        articleLink($this->article, 'Первая', 1);
        articleLink($this->article, 'Вторая', 2);

        $this->get(route('fastleo.resources.related', ['articles', $this->article->id, 'links']))
            ->assertOk()
            ->assertSee('x-data="fastleoReorder(', false)
            ->assertSee('data-reorder-handle', false)
            ->assertSeeInOrder(['Первая', 'Вторая']);
    });

    it('has no handle when the table is narrowed by search or sorted by a column', function (): void {
        articleLink($this->article, 'Первая', 1);

        foreach (['q' => 'Первая', 'sort' => 'title'] as $name => $value) {
            $this->get(route('fastleo.resources.related', ['articles', $this->article->id, 'links', $name => $value]))
                ->assertOk()
                ->assertSee('Первая')
                ->assertDontSee('data-reorder-handle', false);
        }
    });

    it('has no handle for a record that cannot be changed', function (): void {
        articleLink($this->article, 'Первая', 1);
        Gate::policy(ArticleLink::class, NoUpdateLinkPolicy::class);

        $this->get(route('fastleo.resources.related', ['articles', $this->article->id, 'links']))
            ->assertOk()
            ->assertSee('Первая')
            ->assertDontSee('data-reorder-handle', false);
    });
});

describe('moving a record', function (): void {
    it('puts a record after a neighbour and renumbers the records of this parent only', function (): void {
        $first = articleLink($this->article, 'А', 1);
        $second = articleLink($this->article, 'Б', 2);
        $third = articleLink($this->article, 'В', 3);
        articleLink($this->other, 'Чужая', 1);

        $this->postJson($this->reorderUrl, ['id' => $first, 'after' => $third])->assertOk()->assertJson(['ok' => true]);

        expect(linkTitles($this->article))->toBe(['Б', 'В', 'А'])
            ->and(DB::table('article_links')->where('id', $second)->value('sort'))->toBe(1)
            ->and(linkTitles($this->other))->toBe(['Чужая'])
            ->and(DB::table('article_links')->where('article_id', $this->other->id)->value('sort'))->toBe(1);
    });

    it('puts a record before a neighbour', function (): void {
        articleLink($this->article, 'А', 1);
        $second = articleLink($this->article, 'Б', 2);
        $third = articleLink($this->article, 'В', 3);

        $this->postJson($this->reorderUrl, ['id' => $third, 'before' => $second])->assertOk();

        expect(linkTitles($this->article))->toBe(['А', 'В', 'Б']);
    });

    it('refuses a neighbour from another parent and an unknown one', function (): void {
        $mine = articleLink($this->article, 'А', 1);
        $foreign = articleLink($this->other, 'Чужая', 1);

        $this->postJson($this->reorderUrl, ['id' => $mine, 'after' => $foreign])->assertUnprocessable();
        $this->postJson($this->reorderUrl, ['id' => $mine, 'after' => 99999])->assertUnprocessable();
        $this->postJson($this->reorderUrl, ['id' => $mine])->assertUnprocessable();
    });

    it('does not move a record of another parent or a missing one', function (): void {
        $foreign = articleLink($this->other, 'Чужая', 1);
        articleLink($this->article, 'А', 1);

        $this->postJson($this->reorderUrl, ['id' => $foreign, 'after' => $foreign])->assertNotFound();
        $this->postJson($this->reorderUrl, ['id' => 99999, 'after' => $foreign])->assertNotFound();
        $this->postJson($this->reorderUrl, ['id' => 'abc'])->assertNotFound();
        $this->postJson($this->reorderUrl, [])->assertNotFound();
        $this->postJson($this->reorderUrl, ['id' => ['x']])->assertNotFound();
    });

    it('answers 404 for an unknown relation, parent or record of the parent', function (): void {
        $this->postJson(route('fastleo.resources.related.reorder', ['articles', $this->article->id, 'nothing']), ['id' => 1])->assertNotFound();
        $this->postJson(route('fastleo.resources.related.reorder', ['articles', 99999, 'links']), ['id' => 1])->assertNotFound();
    });

    it('is forbidden when the policy does not let the admin change the record', function (): void {
        $first = articleLink($this->article, 'А', 1);
        $second = articleLink($this->article, 'Б', 2);
        Gate::policy(ArticleLink::class, NoUpdateLinkPolicy::class);

        $this->postJson($this->reorderUrl, ['id' => $first, 'after' => $second])->assertForbidden();

        expect(linkTitles($this->article))->toBe(['А', 'Б']);
    });

    it('explains a database error and keeps the order', function (): void {
        $first = articleLink($this->article, 'А', 1);
        $second = articleLink($this->article, 'Б', 2);
        ArticleLink::saving(function (): void {
            throw new QueryException('sqlite', 'update article_links', [], new PDOException('FOREIGN KEY constraint failed'));
        });

        $this->postJson($this->reorderUrl, ['id' => $first, 'after' => $second])->assertUnprocessable()->assertJsonStructure(['message']);

        expect(linkTitles($this->article))->toBe(['А', 'Б']);
    });

    it('keeps the numbers the records had instead of numbering them from 1', function (): void {
        $first = articleLink($this->article, 'А', 5);
        articleLink($this->article, 'Б', 10);
        $third = articleLink($this->article, 'В', 20);
        $foreign = articleLink($this->other, 'Чужая', 3);

        $this->postJson($this->reorderUrl, ['id' => $third, 'before' => $first])->assertOk();

        expect(linkTitles($this->article))->toBe(['В', 'А', 'Б'])
            ->and(DB::table('article_links')->where('article_id', $this->article->id)->orderBy('sort')->pluck('sort')->all())->toBe([5, 10, 20])
            ->and(DB::table('article_links')->where('id', $foreign)->value('sort'))->toBe(3);
    });

    it('numbers the records from 1 when their numbers repeat, so that dragging still changes the order', function (): void {
        articleLink($this->article, 'А', 0);
        articleLink($this->article, 'Б', 0);
        $third = articleLink($this->article, 'В', 0);
        $first = DB::table('article_links')->where('title', 'А')->value('id');

        $this->postJson($this->reorderUrl, ['id' => $third, 'before' => $first])->assertOk();

        expect(linkTitles($this->article))->toBe(['В', 'А', 'Б'])
            ->and(DB::table('article_links')->where('article_id', $this->article->id)->orderBy('sort')->pluck('sort')->all())->toBe([1, 2, 3]);
    });

    it('is forbidden without the right to list the child section, and the order stays', function (): void {
        $first = articleLink($this->article, 'А', 1);
        $second = articleLink($this->article, 'Б', 2);
        Gate::policy(ArticleLink::class, ClosedListLinkPolicy::class);

        $this->postJson($this->reorderUrl, ['id' => $first, 'after' => $second])->assertForbidden();

        expect(linkTitles($this->article))->toBe(['А', 'Б']);
    });

    it('is forbidden without the right to open the parent record, and the order stays', function (): void {
        $first = articleLink($this->article, 'А', 1);
        $second = articleLink($this->article, 'Б', 2);
        Gate::policy(Article::class, NoViewArticlePolicy::class);

        $this->postJson($this->reorderUrl, ['id' => $first, 'after' => $second])->assertForbidden();

        expect(linkTitles($this->article))->toBe(['А', 'Б']);
    });

    it('is forbidden for a read-only child section', function (): void {
        $first = articleLink($this->article, 'А', 1);
        $second = articleLink($this->article, 'Б', 2);
        ArticleResource::$child = ReadonlyLinkResource::class;

        $this->postJson($this->reorderUrl, ['id' => $first, 'after' => $second])->assertForbidden();

        expect(linkTitles($this->article))->toBe(['А', 'Б']);
    });

    it('answers 404 for a child section without a sort column', function (): void {
        $first = articleLink($this->article, 'А', 1);
        $second = articleLink($this->article, 'Б', 2);
        ArticleResource::$child = UnsortedLinkResource::class;

        $this->postJson($this->reorderUrl, ['id' => $first, 'after' => $second])->assertNotFound();

        expect(linkTitles($this->article))->toBe(['А', 'Б']);
    });

    it('answers 404 for a child section with a tree', function (): void {
        $first = articleLink($this->article, 'А', 1);
        $second = articleLink($this->article, 'Б', 2);
        ArticleResource::$child = TreedLinkResource::class;

        $this->postJson($this->reorderUrl, ['id' => $first, 'after' => $second])->assertNotFound();

        expect(linkTitles($this->article))->toBe(['А', 'Б']);
    });

    it('is closed to anyone who is not an admin', function (): void {
        $first = articleLink($this->article, 'А', 1);
        auth(Fastleo::GUARD)->logout();

        $this->postJson($this->reorderUrl, ['id' => $first, 'after' => $first])->assertUnauthorized();
    });
});
