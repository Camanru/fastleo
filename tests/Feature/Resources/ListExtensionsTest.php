<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Related;
use Fastleo\Tests\Fixtures\ListUx\ArticleResource;
use Fastleo\Tests\Fixtures\ListUx\ClosedProductPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\Feedback;
use Workbench\App\Models\Product;

beforeEach(function (): void {
    useFixtureResources('ListUx');
    View::addNamespace('listux', __DIR__.'/../../Fixtures/ListUx/views');
    $this->actingAs(admin(), Fastleo::GUARD);
    $this->open = Category::factory()->create(['title' => 'Открытая']);
    $this->closed = Category::factory()->create(['title' => 'Закрытая']);
});

afterEach(function (): void {
    ArticleResource::$primaryKey = 'take-article';
});

describe('filters applied by a button', function (): void {
    it('puts the button in the filter row and stops the fields from submitting by themselves', function (): void {
        $html = $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->getContent();

        expect($html)->toContain('data-filters-actions')
            ->toContain('Фильтровать')
            ->and(preg_match('/<select name="filter\[status\]"[^>]*x-on:change/', $html))->toBe(0);
    });

    it('shows the reset link next to the button only when something is filtered', function (): void {
        $plain = (string) $this->get(route('fastleo.resources.index', 'articles'))->getContent();
        $filtered = (string) $this->get(route('fastleo.resources.index', ['articles', 'filter' => ['status' => 'draft']]))->getContent();

        expect(preg_match('/data-filters-actions.*?Сбросить/s', $plain))->toBe(0)
            ->and(preg_match('/data-filters-actions.*?Сбросить/s', $filtered))->toBe(1)
            // Вторая «Сбросить» у кнопки «Фильтры» прячется, пока открыта панель со своей.
            ->and(preg_match('/x-show="! filtersOpen"[^>]*data-search-reset/', $filtered))->toBe(1);
    });

    it('keeps the immediate filters and has no button in a section that did not ask for it', function (): void {
        useFixtureResources('Filters');

        $html = $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->getContent();

        expect($html)->not->toContain('data-filters-actions')
            ->and(preg_match('/<select name="filter\[status\]"[^>]*x-on:change="\$el\.form\.requestSubmit\(\)"/', $html))->toBe(1);
    });
});

describe('collapsed filter panel', function (): void {
    it('stays closed even when filters are applied and remembers the choice for the tab', function (): void {
        $html = $this->get(route('fastleo.resources.index', ['articles', 'filter' => ['status' => 'draft']]))->assertOk()->getContent();

        expect($html)->toContain('fastleo-filters-articles')
            ->toContain('sessionStorage')
            ->not->toContain('filtersOpen: true')
            ->toContain('filtersApplied: true');
    });

    it('opens the panel by itself when filters are applied in a section that is not collapsed', function (): void {
        useFixtureResources('Filters');

        $html = $this->get(route('fastleo.resources.index', ['articles', 'filter' => ['status' => 'draft']]))->assertOk()->getContent();

        expect($html)->toContain('filtersOpen: true')->not->toContain('sessionStorage');
    });
});

describe('primary row action', function (): void {
    it('draws the labelled button of the action in every row, with the label of the record', function (): void {
        Article::factory()->create(['title' => 'Альфа']);
        Article::factory()->create(['title' => 'Бета']);

        $html = $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->getContent();

        expect($html)->toContain('Взять «Альфа»')->toContain('Взять «Бета»')
            ->and(preg_match_all('/Взять «Альфа»/', $html))->toBeGreaterThanOrEqual(1);
    });

    it('draws nothing when the key is not one of the actions available to the record', function (): void {
        Article::factory()->create(['title' => 'Альфа']);
        ArticleResource::$primaryKey = 'missing-action';

        $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertDontSee('Взять «Альфа»');
    });

    it('is not drawn for records in the trash', function (): void {
        Article::factory()->create(['title' => 'Альфа'])->delete();

        $this->get(route('fastleo.resources.index', ['articles', 'trashed' => 1]))->assertOk()->assertDontSee('Взять «Альфа»');
    });
});

describe('record page hooks', function (): void {
    it('shows the summary card, the header buttons and the own delete warning on the edit page', function (): void {
        $article = Article::factory()->create(['title' => 'Альфа']);

        $this->get(route('fastleo.resources.edit', ['articles', $article->id]))
            ->assertOk()
            ->assertSee('<p data-summary>Карточка: Альфа</p>', false)
            ->assertSee('Добавить соседнюю')
            ->assertSee('Удалить «Альфа» вместе со всем?');
    });

    it('asks the own delete warning from the delete button of a list row too', function (): void {
        Article::factory()->create(['title' => 'Альфа']);

        $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertSee('Удалить «Альфа» вместе со всем?');
    });

    it('shows none of them on the create form or in the modal frame', function (): void {
        $article = Article::factory()->create(['title' => 'Альфа']);

        $this->get(route('fastleo.resources.create', 'articles'))->assertOk()->assertDontSee('data-summary', false)->assertDontSee('Добавить соседнюю');
        $this->get(route('fastleo.resources.edit', ['articles', $article->id, 'frame' => 1]))->assertOk()->assertDontSee('data-summary', false)->assertDontSee('Добавить соседнюю');
    });

    it('has the standard page on a section that defines none of them', function (): void {
        useFixtureResources('Filters');
        $article = Article::factory()->create(['title' => 'Альфа']);

        $this->get(route('fastleo.resources.edit', ['articles', $article->id]))
            ->assertOk()
            ->assertDontSee('data-summary', false)
            ->assertDontSee('вместе со всем');
    });

    it('can leave the add button off a related table', function (): void {
        $related = Related::make('articles', ArticleResource::class);

        expect($related->hasAddButton())->toBeTrue()
            ->and($related->withoutAddButton()->hasAddButton())->toBeFalse()
            ->and($related->withoutAddButton(false)->hasAddButton())->toBeTrue();

        $url = route('fastleo.resources.related', ['categories', $this->open->id, 'articles']);

        $this->get($url)->assertOk()->assertDontSee(route('fastleo.resources.create', 'articles'), false);
    });
});

describe('related table without the footer on a single page', function (): void {
    it('can be switched on and off', function (): void {
        $related = Related::make('articles', ArticleResource::class);

        expect($related->hasSinglePageFooter())->toBeTrue()
            ->and($related->withoutSinglePageFooter()->hasSinglePageFooter())->toBeFalse()
            ->and($related->withoutSinglePageFooter(false)->hasSinglePageFooter())->toBeTrue();
    });

    it('hides the line of records while they fit on one page and keeps it for pages and the full list', function (): void {
        $url = route('fastleo.resources.related', ['categories', $this->open->id, 'articles']);
        Article::factory()->count(2)->create(['category_id' => $this->open->id]);

        $this->get($url)->assertOk()->assertDontSee('Записи 1–2 из 2');

        Article::factory()->create(['category_id' => $this->open->id]);

        $this->get($url)->assertOk()->assertSee('Записи 1–2 из 3');
        $this->get($url.'?per_page=all')->assertOk()->assertSee('Записи 1–3 из 3');
    });
});

describe('opening a record without marking it read', function (): void {
    it('leaves the read mark alone on open and changes it only by the action', function (): void {
        $feedback = Feedback::factory()->create();

        $this->get(route('fastleo.resources.edit', ['feedback', $feedback->id]))
            ->assertOk()
            ->assertSee(route('fastleo.resources.action', ['feedback', 'mark-read']), false);

        expect($feedback->fresh()?->read_at)->toBeNull();

        $this->post(route('fastleo.resources.action', ['feedback', 'mark-read']), ['ids' => [(string) $feedback->id]])->assertRedirect();

        expect($feedback->fresh()?->read_at)->not->toBeNull();
    });
});

describe('belongs-to field with a required ability', function (): void {
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    function ability(array $data = []): array
    {
        return ['title' => 'Заголовок', 'status' => 'draft', ...$data];
    }

    it('refuses to attach a new record to a parent that the administrator may not change', function (): void {
        $this->post(route('fastleo.resources.store', 'articles'), ability(['category_id' => (string) $this->closed->id]))
            ->assertSessionHasErrors('category_id');

        expect(session('errors')->first('category_id'))->toContain('Нет прав на запись')
            ->and(Article::query()->count())->toBe(0);
    });

    it('attaches to a parent that may be changed', function (): void {
        $this->post(route('fastleo.resources.store', 'articles'), ability(['category_id' => (string) $this->open->id]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        expect(Article::query()->value('category_id'))->toBe($this->open->id);
    });

    it('refuses to move a record to such a parent', function (): void {
        $article = Article::factory()->create(['category_id' => $this->open->id]);

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), ability(['category_id' => (string) $this->closed->id]))
            ->assertSessionHasErrors('category_id');

        expect($article->fresh()?->category_id)->toBe($this->open->id);
    });

    it('does not check the parent that the record already has', function (): void {
        $article = Article::factory()->create(['title' => 'Старое', 'category_id' => $this->closed->id]);

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), ability(['title' => 'Новое', 'category_id' => (string) $this->closed->id]))
            ->assertSessionHasNoErrors();

        expect($article->fresh()?->title)->toBe('Новое');
    });

    it('lets the other rules report an empty, unknown or garbage value instead of failing on it', function (mixed $value): void {
        $this->post(route('fastleo.resources.store', 'articles'), ability(['category_id' => $value]))->assertSessionHasErrors('category_id');

        expect(session('errors')->first('category_id'))->not->toContain('Нет прав на запись');
    })->with(['unknown id' => '99999999', 'text' => 'abc', 'huge number' => '99999999999999999999999', 'array' => [['1']]]);

    it('accepts an empty value of an optional field', function (): void {
        $this->post(route('fastleo.resources.store', 'articles'), ability(['category_id' => '']))->assertSessionHasNoErrors();
    });
});

describe('parent without a section of its own', function (): void {
    it('is allowed when the model has no Policy either', function (): void {
        $product = Product::factory()->create(['title' => 'Закрытый']);

        $this->post(route('fastleo.resources.store', 'product-reviews'), ['author' => 'Иван', 'product_id' => (string) $product->id])->assertSessionHasNoErrors();
    });

    it('is checked by the Policy of its model', function (): void {
        Gate::policy(Product::class, ClosedProductPolicy::class);
        $open = Product::factory()->create(['title' => 'Открытый']);
        $closed = Product::factory()->create(['title' => 'Закрытый']);

        $this->post(route('fastleo.resources.store', 'product-reviews'), ['author' => 'Иван', 'product_id' => (string) $closed->id])->assertSessionHasErrors('product_id');
        $this->post(route('fastleo.resources.store', 'product-reviews'), ['author' => 'Иван', 'product_id' => (string) $open->id])->assertSessionHasNoErrors();
    });
});

describe('prefill with garbage in a relation', function (): void {
    it('opens the create form whatever is put into the key of a BelongsTo field', function (mixed $value): void {
        $this->get(route('fastleo.resources.create', ['articles', 'prefill' => ['category_id' => $value]]))->assertOk();
    })->with(['array' => [['1']], 'text' => 'abc', 'huge number' => '99999999999999999999999', 'unknown id' => '99999999']);
});
