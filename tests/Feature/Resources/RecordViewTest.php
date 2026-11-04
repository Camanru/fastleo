<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Policies\CountingArticlePolicy;
use Fastleo\Tests\Fixtures\Policies\NoUpdateArticlePolicy;
use Fastleo\Widgets\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Fastleo\ArticleResource;
use Workbench\App\Fastleo\FeedbackResource;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\Feedback;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('has the page of a record that opens as a page when the window is switched off', function (): void {
    config(['fastleo.record_view' => 'page']);
    $article = Article::factory()->create(['title' => 'Приточная']);

    expect(Fastleo::recordView())->toBeTrue();
    $this->get(route('fastleo.resources.show', ['articles', $article->id]))->assertOk()->assertSee('Приточная');
    $this->get(route('fastleo.resources.index', 'articles'))->assertSee(route('fastleo.resources.show', ['articles', $article->id]), false);
});

it('gives the address of a record without an administrator at hand: webhook, queue, command', function (): void {
    config(['fastleo.record_view' => 'page']);
    Gate::policy(Article::class, NoUpdateArticlePolicy::class);
    $article = Article::factory()->create();
    auth(Fastleo::GUARD)->logout();

    expect(Fastleo::recordUrl($article, false))->toBe(route('fastleo.resources.show', ['articles', $article->id], false));
});

it('opens a record in a window by default', function (): void {
    expect((require __DIR__.'/../../../config/fastleo.php')['record_view'])->toBe('modal');

    // Сайт со старым конфигом, где настройки нет: умолчание то же.
    config(['fastleo' => array_diff_key(config()->array('fastleo'), ['record_view' => true])]);

    expect(Fastleo::recordView())->toBeTrue()
        ->and(Fastleo::recordViewInModal())->toBeTrue();
});

describe('in a window', function (): void {
    it('is not in a window unless asked', function (): void {
        config(['fastleo.record_view' => 'page']);
        $article = Article::factory()->create();

        expect(Fastleo::recordViewInModal())->toBeFalse()
            ->and(ArticleResource::viewModalUrl(new ArticleResource()->viewUrl($article)))->toBeNull();
        $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertDontSee('data-record-view', false);
    });

    it('opens the eye button of the list in a window and shows the record page without the menu', function (): void {
        config(['fastleo.record_view' => 'modal']);
        $article = Article::factory()->create(['title' => 'Приточная']);
        $window = route('fastleo.resources.show', ['articles', $article->id, 'frame' => 1]);

        expect(Fastleo::recordViewInModal())->toBeTrue()
            ->and(ArticleResource::viewModalUrl(new ArticleResource()->viewUrl($article)))->toBe($window)
            ->and(ArticleResource::viewModalUrl(null))->toBeNull();
        $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertSee('data-record-view="'.$window.'"', false);
        $this->get($window)->assertOk()->assertSee('Приточная')->assertDontSee('fastleo-menu', false)->assertDontSee('<!DOCTYPE html>', false)
            ->assertDontSee(route('fastleo.resources.edit', ['articles', $article->id]), false)
            ->assertDontSee('<form', false);
    });

    it('opens the link to a related record and the rows of the table widget in a window', function (): void {
        config(['fastleo.record_view' => 'modal']);
        $category = Category::factory()->create(['title' => 'Вентиляция']);
        $article = Article::factory()->create(['category_id' => $category->id]);
        $window = route('fastleo.resources.show', ['categories', $category->id, 'frame' => 1]);

        $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertSee('href="'.route('fastleo.resources.show', ['categories', $category->id]).'" class="hover:text-primary-text hover:underline" data-related-link data-record-view="'.$window.'"', false);
        $widget = Table::make()->resource(ArticleResource::class);
        $rowWindow = route('fastleo.resources.show', ['articles', $article->id, 'frame' => 1]);

        expect($widget->resolve()['rows'][0]['modalUrl'])->toBe($rowWindow)
            ->and(view($widget->view(), $widget->resolve())->render())->toContain('data-record-view="'.$rowWindow.'"')->not->toContain('data-record-modal');
    });

    it('asks the policy once per row for the page and the window of the table widget', function (): void {
        config(['fastleo.record_view' => 'modal']);
        Gate::policy(Article::class, CountingArticlePolicy::class);
        CountingArticlePolicy::$views = 0;
        Article::factory()->count(3)->create();

        Table::make()->resource(ArticleResource::class)->resolve();

        expect(CountingArticlePolicy::$views)->toBe(3);
    });

    it('has no window address when the page of a record is switched off', function (): void {
        config(['fastleo.record_view' => false]);
        $article = Article::factory()->create();

        expect(Fastleo::recordViewInModal())->toBeFalse()
            ->and(ArticleResource::viewModalUrl(new ArticleResource()->viewUrl($article)))->toBeNull();
    });
});

describe('switched off', function (): void {
    beforeEach(function (): void {
        config(['fastleo.record_view' => false]);
    });

    it('answers 404 at the page of a record', function (): void {
        $article = Article::factory()->create();

        $this->get(route('fastleo.resources.show', ['articles', $article->id]))->assertNotFound();
    });

    it('has no eye button in the list and opens the form from the first column', function (): void {
        $article = Article::factory()->create(['title' => 'Приточная']);

        $this->get(route('fastleo.resources.index', 'articles'))
            ->assertOk()
            ->assertDontSee('href="'.route('fastleo.resources.show', ['articles', $article->id]).'"', false)
            ->assertSee('href="'.route('fastleo.resources.edit', ['articles', $article->id]).'"', false);
    });

    it('leaves a record that cannot be edited as plain text', function (): void {
        Gate::policy(Article::class, NoUpdateArticlePolicy::class);
        $article = Article::factory()->create(['title' => 'Приточная']);

        $this->get(route('fastleo.resources.index', 'articles'))
            ->assertOk()
            ->assertSee('Приточная')
            ->assertDontSee('href="'.route('fastleo.resources.show', ['articles', $article->id]).'"', false)
            ->assertDontSee('href="'.route('fastleo.resources.edit', ['articles', $article->id]).'"', false);
    });

    it('leaves the records of a read-only section as plain text', function (): void {
        $feedback = Feedback::factory()->create(['name' => 'Мария']);

        $this->get(route('fastleo.resources.index', 'feedback'))
            ->assertOk()
            ->assertSee('Мария')
            ->assertDontSee('/fastleo/feedback/'.$feedback->id, false);
    });

    it('has no link to a record in the trash', function (): void {
        $article = Article::factory()->create();
        $article->delete();

        $this->get(route('fastleo.resources.index', ['resource' => 'articles', 'trashed' => 1]))
            ->assertOk()
            ->assertDontSee('href="'.route('fastleo.resources.show', ['articles', $article->id]).'"', false)
            ->assertDontSee('/fastleo/articles/'.$article->id.'/edit', false);
    });

    it('gives an address for notifications: the form, or nothing for a model without a section', function (): void {
        $article = Article::factory()->create();
        $feedback = Feedback::factory()->create();

        expect(Fastleo::recordUrl($article))->toBe(Fastleo::adminUrl(route('fastleo.resources.edit', ['articles', $article->id], false)))
            ->and(Fastleo::recordUrl($article, false))->toBe(route('fastleo.resources.edit', ['articles', $article->id], false))
            ->and(Fastleo::recordUrl($feedback))->toBe(Fastleo::adminUrl(route('fastleo.resources.edit', ['feedback', $feedback->id], false)))
            ->and(Fastleo::recordUrl(new class extends Model {}))->toBeNull();
    });

    it('links a related record to its form and searches with it', function (): void {
        $category = Category::factory()->create(['title' => 'Приточные системы', 'code' => 'supply']);
        Article::factory()->create(['category_id' => $category->id]);

        $this->get(route('fastleo.resources.index', 'articles'))->assertOk()
            ->assertSee('href="'.route('fastleo.resources.edit', ['categories', $category->id]).'" class="hover:text-primary-text hover:underline" data-related-link>', false)
            ->assertDontSee('href="'.route('fastleo.resources.show', ['categories', $category->id]).'"', false);

        $groups = $this->getJson(route('fastleo.search', ['q' => 'Приточные']))->assertOk()->json('groups');

        expect($groups[0]['items'][0]['url'])->toBe(route('fastleo.resources.edit', ['categories', $category->id]));
    });

    it('takes the section of the table widget to the form, and to no page when it cannot be opened', function (): void {
        $feedback = Feedback::factory()->create();
        $article = Article::factory()->create();

        $rows = fn (string $resource): array => Table::make()->resource($resource)->resolve()['rows'];

        expect($rows(ArticleResource::class)[0]['url'])->toBe(route('fastleo.resources.edit', ['articles', $article->id]))
            ->and($rows(FeedbackResource::class)[0]['url'])->toBeNull();
    });
});

it('gives the page of a record for notifications while the page exists', function (): void {
    $article = Article::factory()->create();

    expect(Fastleo::recordUrl($article))->toBe(Fastleo::adminUrl(route('fastleo.resources.show', ['articles', $article->id], false)));
});
