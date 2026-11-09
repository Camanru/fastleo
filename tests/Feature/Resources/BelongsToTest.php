<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\BelongsTo;
use Fastleo\Tests\Fixtures\Policies\ViewlessCategoryPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Js;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

describe('list', function (): void {
    beforeEach(function (): void {
        useFixtureResources('Filters');
    });

    it('shows the category as a link to its page', function (): void {
        config(['fastleo.record_view' => 'page']);
        $news = Category::factory()->create(['title' => 'Новости']);
        Article::factory()->create(['title' => 'Статья', 'category_id' => $news->id]);

        $this->get(route('fastleo.resources.index', 'articles'))
            ->assertOk()
            ->assertSee('<a href="'.route('fastleo.resources.show', ['categories', $news->id]).'" class="hover:text-primary-text hover:underline" data-related-link>Новости</a>', false);
    });

    it('does not make a link of a category the administrator may not view', function (): void {
        $news = Category::factory()->create(['title' => 'Новости']);
        Article::factory()->create(['title' => 'Статья', 'category_id' => $news->id]);
        Gate::policy(Category::class, ViewlessCategoryPolicy::class);

        $this->get(route('fastleo.resources.index', 'articles'))
            ->assertOk()
            ->assertSee('Новости')
            ->assertDontSee(route('fastleo.resources.show', ['categories', $news->id]), false);
    });

    it('leaves the title of a category plain when the model has no section', function (): void {
        useFixtureResources('LinkNone');
        $news = Category::factory()->create(['title' => 'Новости']);
        Article::factory()->create(['title' => 'Статья', 'category_id' => $news->id]);

        $this->get(route('fastleo.resources.index', 'articles'))
            ->assertOk()
            ->assertSee('Новости')
            ->assertDontSee('data-related-link', false);
    });

    it('does not link a category that is edited in the cell', function (): void {
        useFixtureResources('LinkInline');
        $news = Category::factory()->create(['title' => 'Новости']);
        Article::factory()->create(['title' => 'Статья', 'category_id' => $news->id]);

        $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertDontSee('data-related-link', false);
    });

    it('does not wrap a category rendered as HTML into another link', function (): void {
        useFixtureResources('LinkHtml');
        $news = Category::factory()->create(['title' => 'Новости']);
        Article::factory()->create(['title' => 'Статья', 'category_id' => $news->id]);

        $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertSee('<b>', false)->assertDontSee('data-related-link', false);
    });

    it('does not link an empty value', function (): void {
        $article = Article::factory()->create(['title' => 'Без категории', 'category_id' => null]);

        $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertSee('Без категории')->assertDontSee('data-related-link', false);
        expect(Fastleo::resources()->find('articles')?->schema()->field('category_id')?->linkUrl($article))->toBeNull();
    });

    it('does not link the category in the trash', function (): void {
        $news = Category::factory()->create(['title' => 'Новости']);
        Article::factory()->create(['title' => 'Статья', 'category_id' => $news->id])->delete();

        $this->get(route('fastleo.resources.index', ['resource' => 'articles', 'trashed' => 1]))->assertOk()->assertDontSee('data-related-link', false);
    });
});

describe('form', function (): void {
    it('offers all categories in the page in tree order and shows the chosen one', function (): void {
        $news = Category::factory()->create(['title' => 'Новости']);
        Category::factory()->create(['title' => 'Акции']);
        $article = Article::factory()->create(['category_id' => $news->id]);

        $response = $this->get(route('fastleo.resources.edit', ['articles', $article->id]));

        $response->assertOk()
            ->assertSee('fastleoCombobox', false)
            ->assertSee(Js::from([
                'multiple' => false,
                'options' => [['value' => (string) $news->id, 'label' => 'Новости'], ['value' => (string) Category::query()->where('title', 'Акции')->value('id'), 'label' => 'Акции']],
                'selected' => [['value' => (string) $news->id, 'label' => 'Новости']],
                'url' => null,
                'remote' => false,
                'depends' => null,
                'record' => null,
            ])->toHtml(), false);
    });

    it('switches to server search when there are more categories than the page holds', function (): void {
        config(['fastleo.relations.preload' => 2]);
        Category::factory()->count(3)->create();

        $this->get(route('fastleo.resources.create', 'articles'))
            ->assertOk()
            ->assertSee(Js::from([
                'multiple' => false,
                'options' => [],
                'selected' => [],
                'url' => route('fastleo.resources.options', ['articles', 'category_id']),
                'remote' => true,
                'depends' => null,
                'record' => null,
            ])->toHtml(), false);
    });

    it('shows a readonly relation as text', function (): void {
        useFixtureResources('Relations');
        $article = Article::factory()->create(['category_id' => Category::factory()->create(['title' => 'Новости'])->id]);

        $this->get(route('fastleo.resources.edit', ['locked-articles', $article->id]))
            ->assertOk()
            ->assertDontSee('fastleoCombobox', false)
            ->assertSee('Новости');
    });
});

describe('saving', function (): void {
    it('saves and clears the related record', function (): void {
        $category = Category::factory()->create();

        $this->post(route('fastleo.resources.store', 'articles'), articleData(['category_id' => (string) $category->id]))->assertRedirect();
        $article = Article::query()->sole();
        expect($article->category_id)->toBe($category->id);

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['category_id' => '']));
        expect($article->fresh()?->category_id)->toBeNull();
    });

    it('rejects a missing or malformed related record', function (mixed $value, string $message): void {
        $this->post(route('fastleo.resources.store', 'articles'), articleData(['category_id' => $value]))
            ->assertSessionHasErrors(['category_id' => $message]);

        expect(Article::query()->count())->toBe(0);
    })->with([
        'missing' => ['999', 'Выбрано недопустимое значение поля «Категория».'],
        'not a number' => ['abc', 'Поле «Категория» должно быть целым числом.'],
        'array' => [['1'], 'Поле «Категория» должно быть целым числом.'],
    ]);
});

it('keeps a saved link to a record that is outside the options now', function (): void {
    useFixtureResources('Relations');
    $hidden = Category::factory()->create(['code' => null, 'title' => 'Скрытая']);
    $article = Article::factory()->create(['category_id' => $hidden->id]);

    $this->get(route('fastleo.resources.edit', ['scoped-articles', $article->id]))
        ->assertOk()
        ->assertSee(Js::from([
            'multiple' => false,
            'options' => [],
            'selected' => [['value' => (string) $hidden->id, 'label' => 'Скрытая']],
            'url' => null,
            'remote' => false,
            'depends' => null,
            'record' => null,
        ])->toHtml(), false);

    $this->put(route('fastleo.resources.update', ['scoped-articles', $article->id]), ['title' => 'Новый заголовок', 'category_id' => (string) $hidden->id])
        ->assertSessionHasNoErrors();

    expect($article->fresh()?->only('title', 'category_id'))->toBe(['title' => 'Новый заголовок', 'category_id' => $hidden->id]);

    $other = Category::factory()->create(['code' => null]);
    $this->put(route('fastleo.resources.update', ['scoped-articles', $article->id]), ['title' => 'Статья', 'category_id' => (string) $other->id])
        ->assertSessionHasErrors('category_id');
});

it('labels a saved link with the key when a global scope of the model hides the related record', function (): void {
    useFixtureResources('Relations');
    $gone = Category::factory()->create(['code' => null]);
    $article = Article::factory()->create(['category_id' => $gone->id]);
    // Как запись в корзине: глобальный скоуп модели прячет её и от подписи.
    Category::addGlobalScope('hidden', fn (Builder $query) => $query->whereKeyNot($gone->id));
    $this->beforeApplicationDestroyed(fn () => Category::clearBootedModels());

    $this->get(route('fastleo.resources.edit', ['scoped-articles', $article->id]))
        ->assertOk()
        ->assertSee(Js::from([
            'multiple' => false,
            'options' => [],
            'selected' => [['value' => (string) $gone->id, 'label' => (string) $gone->id]],
            'url' => null,
            'remote' => false,
            'depends' => null,
            'record' => null,
        ])->toHtml(), false);
});

it('shows the related title in the list with one query for all rows', function (): void {
    $categories = Category::factory()->count(3)->create();

    foreach ($categories as $category) {
        Article::factory()->count(2)->create(['category_id' => $category->id]);
    }

    DB::enableQueryLog();
    $response = $this->get(route('fastleo.resources.index', 'articles'));
    // Подписи строк — запрос `where id in (...)`; варианты фильтра «Категория» над списком сюда не входят.
    $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains(str_replace('`', '"', $query['query']), '"categories"') && str_contains($query['query'], ' in ('));

    $response->assertOk()->assertSee($categories[0]->title)->assertSee($categories[2]->title);
    expect($queries)->toHaveCount(1);
});

describe('options', function (): void {
    it('finds categories by title', function (): void {
        Category::factory()->create(['title' => 'Новости магазина']);
        Category::factory()->create(['title' => 'Акции']);

        $this->getJson(route('fastleo.resources.options', ['articles', 'category_id', 'q' => 'НОВОСТИ']))
            ->assertOk()
            ->assertExactJson(['options' => [['value' => (string) Category::query()->where('title', 'Новости магазина')->value('id'), 'label' => 'Новости магазина']]]);
    });

    it('returns at most twenty options', function (): void {
        Category::factory()->count(25)->create();

        expect($this->getJson(route('fastleo.resources.options', ['articles', 'category_id']))->json('options'))->toHaveCount(20);
    });

    it('answers 404 for a field that has no options', function (string $resource, string $field): void {
        useFixtureResources('Relations');

        $this->getJson(route('fastleo.resources.options', [$resource, $field]))->assertNotFound();
    })->with([
        'text field' => ['scoped-articles', 'title'],
        'unknown field' => ['scoped-articles', 'nothing'],
        'readonly relation' => ['locked-articles', 'category_id'],
    ]);
});

it('names a related record by the main language even when another language comes first in the array', function (): void {
    config(['fastleo.locales' => ['ru', 'en']]);
    $field = BelongsTo::make('category_id')->titleColumn('title');

    expect($field->titleOf(new Category(['title' => ['en' => 'News', 'ru' => 'Новости']])))->toBe('Новости');
});

it('names a related record by the main language when its title column is translatable', function (): void {
    config(['fastleo.locales' => ['ru', 'en']]);
    $field = BelongsTo::make('category_id')->titleColumn('title');

    expect($field->titleOf(new Category(['title' => ['ru' => 'Новости', 'en' => 'News']])))->toBe('Новости')
        ->and($field->titleOf(new Category(['title' => ['ru' => '', 'en' => 'News']])))->toBe('News')
        ->and($field->titleOf(new Category(['title' => ['ru' => '']])))->toBe('');
});
