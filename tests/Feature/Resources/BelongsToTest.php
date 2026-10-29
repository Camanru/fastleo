<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
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
    $hidden = Category::factory()->create(['code' => null]);
    $article = Article::factory()->create(['category_id' => $hidden->id]);

    $this->get(route('fastleo.resources.edit', ['scoped-articles', $article->id]))
        ->assertOk()
        ->assertSee(Js::from([
            'multiple' => false,
            'options' => [],
            'selected' => [['value' => (string) $hidden->id, 'label' => (string) $hidden->id]],
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
        Category::factory()->create(['title' => 'Новости клиники']);
        Category::factory()->create(['title' => 'Акции']);

        $this->getJson(route('fastleo.resources.options', ['articles', 'category_id']).'?q=клиник')
            ->assertOk()
            ->assertExactJson(['options' => [['value' => (string) Category::query()->where('title', 'Новости клиники')->value('id'), 'label' => 'Новости клиники']]]);
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
