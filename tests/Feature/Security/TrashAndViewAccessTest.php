<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Policies\NoTrashControlArticlePolicy;
use Fastleo\Tests\Fixtures\Policies\NoViewArticlePolicy;
use Fastleo\Tests\Fixtures\Policies\NoViewFeedbackPolicy;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Article;
use Workbench\App\Models\Feedback;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

describe('the trash', function (): void {
    beforeEach(function (): void {
        Gate::policy(Article::class, NoTrashControlArticlePolicy::class);
        $this->trashed = Article::factory()->create(['title' => 'В корзине']);
        $this->trashed->delete();
    });

    it('does not restore a record without the restore right and leaves it in the trash', function (): void {
        $this->post(route('fastleo.resources.restore', ['articles', $this->trashed->id]))->assertForbidden();

        expect(Article::withTrashed()->find($this->trashed->id)?->trashed())->toBeTrue();
    });

    it('does not delete a record for good without the forceDelete right', function (): void {
        $this->delete(route('fastleo.resources.force-delete', ['articles', $this->trashed->id]))->assertForbidden();

        expect(Article::withTrashed()->whereKey($this->trashed->id)->exists())->toBeTrue();
    });

    it('refuses the bulk restore and delete for good when the policy forbids them, and leaves the records in the trash', function (string $action): void {
        $this->from(route('fastleo.resources.index', ['resource' => 'articles', 'trashed' => 1]))
            ->post(route('fastleo.resources.action', ['articles', $action]), ['ids' => [(string) $this->trashed->id]])
            ->assertForbidden();

        expect(Article::withTrashed()->find($this->trashed->id)?->trashed())->toBeTrue();
    })->with(['restore', 'force-delete']);

    it('still lets the administrator move a record to the trash', function (): void {
        $alive = Article::factory()->create();

        $this->delete(route('fastleo.resources.destroy', ['articles', $alive->id]))->assertRedirect();

        $this->assertSoftDeleted($alive);
    });
});

describe('looking at a record', function (): void {
    it('refuses the page of a record without the view right', function (): void {
        Gate::policy(Article::class, NoViewArticlePolicy::class);
        $article = Article::factory()->create(['title' => 'Секретная запись']);

        $this->get(route('fastleo.resources.show', ['articles', $article->id]))->assertForbidden()->assertDontSee('Секретная запись');
    });

    it('does not mark an unread record as read when the page is refused', function (): void {
        Gate::policy(Feedback::class, NoViewFeedbackPolicy::class);
        $feedback = Feedback::factory()->create(['read_at' => null]);

        $this->get(route('fastleo.resources.show', ['feedback', $feedback->id]))->assertForbidden();

        expect($feedback->fresh()?->read_at)->toBeNull();
    });
});

describe('a record outside the query of the section', function (): void {
    beforeEach(function (): void {
        useFixtureResources('Actions');
        // Раздел «Новости» видит только записи с type = news; страница — не новость.
        $this->page = Article::factory()->create(['type' => 'page', 'title' => 'Чужая страница']);
    });

    it('is not found by any route of one record', function (string $method, string $route, array $extra): void {
        $this->{$method}(route($route, ['articles', $this->page->id, ...$extra]), ['title' => 'Взлом'])->assertNotFound();

        expect($this->page->fresh()?->title)->toBe('Чужая страница');
    })->with([
        'show' => ['get', 'fastleo.resources.show', []],
        'edit' => ['get', 'fastleo.resources.edit', []],
        'update' => ['put', 'fastleo.resources.update', []],
        'destroy' => ['delete', 'fastleo.resources.destroy', []],
        'expand' => ['get', 'fastleo.resources.expand', []],
        'restore' => ['post', 'fastleo.resources.restore', []],
        'force delete' => ['delete', 'fastleo.resources.force-delete', []],
        'inline' => ['patch', 'fastleo.resources.inline', ['title']],
        'toggle' => ['post', 'fastleo.resources.toggle', ['is_published']],
    ]);

    it('is not changed by an action of the section', function (): void {
        $this->from(route('fastleo.resources.index', 'articles'))
            ->post(route('fastleo.resources.action', ['articles', 'rename-articles']), ['ids' => [(string) $this->page->id], 'suffix' => 'взлом'])
            ->assertNotFound();

        expect($this->page->fresh()?->title)->toBe('Чужая страница');
    });
});
