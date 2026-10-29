<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Workbench\App\Models\Article;
use Workbench\App\Models\Topic;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('shows the chosen topics in the form', function (): void {
    $topic = Topic::factory()->create(['title' => 'Здоровье']);
    $article = Article::factory()->create();
    $article->topics()->attach($topic);

    $this->get(route('fastleo.resources.edit', ['articles', $article->id]))
        ->assertOk()
        ->assertSee('name="topics" value=""', false)
        ->assertSee(Js::from([
            'multiple' => true,
            'options' => [['value' => (string) $topic->id, 'label' => 'Здоровье']],
            'selected' => [['value' => (string) $topic->id, 'label' => 'Здоровье']],
            'url' => null,
            'remote' => false,
            'depends' => null,
            'record' => null,
        ])->toHtml(), false);
});

it('syncs and clears the topics', function (): void {
    [$first, $second, $third] = Topic::factory()->count(3)->create()->all();
    $article = Article::factory()->create();
    $article->topics()->attach([$first->id, $second->id]);

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['topics' => [(string) $second->id, (string) $third->id]]))
        ->assertSessionHasNoErrors();
    expect($article->topics()->pluck('topics.id')->sort()->values()->all())->toBe([$second->id, $third->id]);

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['topics' => '']));
    expect($article->topics()->count())->toBe(0);
});

it('leaves the topics alone when the field is not sent', function (): void {
    $article = Article::factory()->create();
    $article->topics()->attach(Topic::factory()->create());

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData());

    expect($article->topics()->count())->toBe(1);
});

it('rejects a missing or repeated topic', function (array $topics, string $error): void {
    $topic = Topic::factory()->create();

    $this->post(route('fastleo.resources.store', 'articles'), articleData(['topics' => array_map(fn (string $value): string => $value === 'own' ? (string) $topic->id : $value, $topics)]))
        ->assertSessionHasErrors($error);

    expect(Article::query()->count())->toBe(0);
})->with([
    'missing' => [['999'], 'topics.0'],
    'repeated' => [['own', 'own'], 'topics.1'],
    'not a number' => [['abc'], 'topics.0'],
]);

describe('field query', function (): void {
    beforeEach(function (): void {
        useFixtureResources('Relations');
    });

    it('keeps links to topics outside the field query and rejects new ones from the request', function (): void {
        $open = Topic::factory()->create(['title' => 'Открытая']);
        $hidden = Topic::factory()->create(['title' => 'Служебная']);
        $other = Topic::factory()->create(['title' => 'Служебная вторая']);
        $article = Article::factory()->create();
        $article->topics()->attach([$open->id, $hidden->id]);

        $this->put(route('fastleo.resources.update', ['scoped-articles', $article->id]), ['title' => 'Статья', 'topics' => [(string) $other->id]])
            ->assertSessionHasErrors('topics.0');

        $this->put(route('fastleo.resources.update', ['scoped-articles', $article->id]), ['title' => 'Статья', 'topics' => ''])
            ->assertSessionHasNoErrors();

        expect($article->topics()->pluck('topics.id')->all())->toBe([$hidden->id]);
    });

    it('lists the topic titles with one query for all rows', function (): void {
        $topics = Topic::factory()->count(2)->sequence(['title' => 'Открытая А'], ['title' => 'Открытая Б'])->create();
        Article::factory()->count(3)->create()->each(fn (Article $article) => $article->topics()->attach($topics));

        DB::enableQueryLog();
        $response = $this->get(route('fastleo.resources.index', 'scoped-articles'));
        $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains(str_replace('`', '"', $query['query']), '"topics"'));

        $response->assertOk()->assertSee('Открытая А, Открытая Б');
        expect($queries)->toHaveCount(1);
    });
});
