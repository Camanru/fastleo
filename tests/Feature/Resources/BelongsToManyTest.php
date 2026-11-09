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

it('puts the saved topics into the form so that only they can be unlinked by it', function (): void {
    [$first, $second] = Topic::factory()->count(2)->create()->all();
    $article = Article::factory()->create();
    $article->topics()->attach([$first->id, $second->id]);

    $this->get(route('fastleo.resources.edit', ['articles', $article->id]))
        ->assertOk()
        ->assertSee('<input type="hidden" name="topics__seen" value="'.collect([$first->id, $second->id])->sort()->implode(',').'">', false);
});

it('does not unlink a topic that another administrator linked after the form was opened', function (): void {
    [$first, $second, $third] = Topic::factory()->count(3)->create()->all();
    $article = Article::factory()->create();
    $article->topics()->attach([$first->id, $second->id]);
    $seen = $first->id.','.$second->id;
    // Пока форма была открыта, другой администратор связал третью тему.
    $article->topics()->attach($third->id);

    // Форма (устаревшая) сохраняет только первую: вторую убрали сами, третьей в ней не было.
    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['topics' => [(string) $first->id], 'topics__seen' => $seen]))
        ->assertSessionHasNoErrors();

    expect($article->topics()->pluck('topics.id')->sort()->values()->all())->toBe([$first->id, $third->id]);
});

it('does not unlink a topic that another administrator linked to a form that was opened with no topics at all', function (): void {
    $article = Article::factory()->create();
    $article->topics()->attach($added = Topic::factory()->create());

    // Пустой список в форме — это `topics__seen=""`; Laravel превращает пустую строку в null, и это не «поля нет».
    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['topics' => '', 'topics__seen' => '']))
        ->assertSessionHasNoErrors();

    expect($article->topics()->pluck('topics.id')->all())->toBe([$added->id]);
});

it('shows the form again after a validation error even if the service field was forged into a list', function (): void {
    $article = Article::factory()->create();
    $article->topics()->attach($topic = Topic::factory()->create());

    $this->from(route('fastleo.resources.edit', ['articles', $article->id]))
        ->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['title' => '', 'topics__seen' => ['x']]))
        ->assertSessionHasErrors('title');

    $this->get(route('fastleo.resources.edit', ['articles', $article->id]))
        ->assertOk()
        ->assertSee('name="topics__seen" value="'.$topic->id.'"', false);
});

it('replaces the whole list as before when the form sends no list of seen topics', function (): void {
    [$first, $second] = Topic::factory()->count(2)->create()->all();
    $article = Article::factory()->create();
    $article->topics()->attach([$first->id, $second->id]);

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['topics' => [(string) $first->id]]))->assertSessionHasNoErrors();

    expect($article->topics()->pluck('topics.id')->all())->toBe([$first->id]);
});

it('rejects a list of seen topics that is not a string and drops its empty and too long keys', function (): void {
    [$first, $second] = Topic::factory()->count(2)->create()->all();
    $article = Article::factory()->create();
    $article->topics()->attach([$first->id, $second->id]);

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['topics' => [(string) $first->id], 'topics__seen' => ['x']]))
        ->assertSessionHasErrors('topics__seen');
    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['topics' => [(string) $first->id], 'topics__seen' => ',,'.str_repeat('9', 65).','.$second->id]))
        ->assertSessionHasNoErrors();

    // Пустые и слишком длинные ключи отброшены, вторая тема была в форме и отвязана.
    expect($article->topics()->pluck('topics.id')->all())->toBe([$first->id]);
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

        // Скрытая тема не показывается снимаемой меткой: снять её формой нельзя.
        $this->get(route('fastleo.resources.edit', ['scoped-articles', $article->id]))->assertOk()->assertSee('Открытая')->assertDontSee('Служебная');

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

it('puts the id of the combobox into its script expressions as a string literal, whatever characters it holds', function (): void {
    $html = view('fastleo::fields.combobox-control', [
        'combobox' => [], 'multiple' => false, 'required' => false, 'placeholder' => '', 'invalid' => false,
        'name' => 'topic', 'path' => 'topic', 'id' => "x'+alert(1)+'",
    ])->render();

    // Апостроф в id не должен закрывать строку в выражении Alpine: id уходит в JS только через @js (апостроф становится \u0027).
    expect($html)->toContain(':id="\'x\\u0027+alert(1)+\\u0027-option-\' + index"')
        ->and($html)->toContain('? \'x\\u0027+alert(1)+\\u0027-option-\' + active : null"')
        ->and($html)->not->toContain("'x'+alert(1)+'");
});
