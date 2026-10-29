<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Policies\TopicOrderPolicy;
use Fastleo\Tests\Fixtures\Policies\TopicUpdatePolicy;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Category;
use Workbench\App\Models\Topic;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Названия тем по порядку.
 *
 * @return list<string>
 */
function topicOrder(): array
{
    return Topic::query()->orderBy('sort')->orderBy('id')->pluck('title')->all();
}

/**
 * Три темы А, Б, В с порядком 1, 2, 3.
 *
 * @return list<Topic>
 */
function threeTopics(): array
{
    return Topic::factory()->count(3)->sequence(['title' => 'А', 'sort' => 1], ['title' => 'Б', 'sort' => 2], ['title' => 'В', 'sort' => 3])->create()->all();
}

it('shows the records in manual order with arrows', function (): void {
    Topic::factory()->create(['title' => 'Вторая', 'sort' => 2]);
    Topic::factory()->create(['title' => 'Первая', 'sort' => 1]);

    $this->get(route('fastleo.resources.index', 'topics'))
        ->assertOk()
        ->assertSeeInOrder(['Первая', 'Вторая'])
        ->assertSee('data-reorder-handle', false)
        ->assertSee('data-reorder="up"', false);
});

it('hides the arrows while the list is searched or sorted by a column', function (array $query): void {
    Topic::factory()->create(['title' => 'Тема']);

    $this->get(route('fastleo.resources.index', ['resource' => 'topics', ...$query]))
        ->assertOk()
        ->assertDontSee('data-reorder="up"', false);
})->with([
    'search' => [['q' => 'Тема']],
    'sort' => [['sort' => 'title']],
]);

it('moves a record up and down with the arrows and renumbers the neighbours', function (): void {
    [, $second, $third] = threeTopics();

    $this->post(route('fastleo.resources.reorder', 'topics'), ['id' => $third->id, 'direction' => 'up'])->assertRedirect();
    expect(topicOrder())->toBe(['А', 'В', 'Б']);

    $this->post(route('fastleo.resources.reorder', 'topics'), ['id' => $second->id, 'direction' => 'up']);
    $this->post(route('fastleo.resources.reorder', 'topics'), ['id' => $second->id, 'direction' => 'up']);
    $this->post(route('fastleo.resources.reorder', 'topics'), ['id' => $second->id, 'direction' => 'up']);
    expect(topicOrder())->toBe(['Б', 'А', 'В'])
        ->and(Topic::query()->orderBy('sort')->pluck('sort')->all())->toBe([1, 2, 3]);
});

it('puts a dragged record before or after a neighbour', function (): void {
    [$first, $second, $third] = threeTopics();

    $this->postJson(route('fastleo.resources.reorder', 'topics'), ['id' => $first->id, 'after' => (string) $third->id])->assertOk()->assertJson(['ok' => true]);
    expect(topicOrder())->toBe(['Б', 'В', 'А']);

    $this->postJson(route('fastleo.resources.reorder', 'topics'), ['id' => $first->id, 'before' => (string) $second->id])->assertOk();
    expect(topicOrder())->toBe(['А', 'Б', 'В']);
});

it('rejects a move without a target or to an unknown neighbour', function (array $data): void {
    [$first] = threeTopics();

    $this->postJson(route('fastleo.resources.reorder', 'topics'), ['id' => $first->id, ...$data])->assertUnprocessable();
    expect(topicOrder())->toBe(['А', 'Б', 'В']);
})->with([
    'nothing' => [[]],
    'unknown neighbour' => [['before' => '999']],
    'wrong direction' => [['direction' => 'left']],
]);

it('answers 404 for a section without manual order or a missing record', function (string $resource, mixed $id): void {
    $this->postJson(route('fastleo.resources.reorder', $resource), ['id' => $id, 'direction' => 'up'])->assertNotFound();
})->with([
    'no sort column' => ['articles', '1'],
    'missing record' => ['topics', '999'],
    'array id' => ['topics', ['1']],
]);

it('moves a tree node only among its siblings', function (): void {
    $root = Category::factory()->create(['title' => 'Корень', 'sort' => 1]);
    $other = Category::factory()->create(['title' => 'Другой корень', 'sort' => 2]);
    $first = Category::factory()->create(['title' => 'Первый', 'parent_id' => $root->id, 'sort' => 1]);
    $second = Category::factory()->create(['title' => 'Второй', 'parent_id' => $root->id, 'sort' => 2]);

    $this->post(route('fastleo.resources.reorder', 'categories'), ['id' => $second->id, 'direction' => 'up']);
    expect($second->fresh()?->sort)->toBe(1)->and($first->fresh()?->sort)->toBe(2)->and($other->fresh()?->sort)->toBe(2);

    $this->postJson(route('fastleo.resources.reorder', 'categories'), ['id' => $second->id, 'after' => (string) $other->id])->assertUnprocessable();
});

it('does not change the order by a GET request', function (): void {
    [, , $third] = threeTopics();

    $this->get(route('fastleo.resources.reorder', 'topics').'?id='.$third->id.'&direction=up')->assertNotFound();

    expect(topicOrder())->toBe(['А', 'Б', 'В']);
});

describe('rights', function (): void {
    it('follows the reorder method of the policy', function (): void {
        Gate::policy(Topic::class, TopicOrderPolicy::class);
        $fixed = Topic::factory()->create(['title' => 'Закреплённая', 'sort' => 1]);
        $movable = Topic::factory()->create(['title' => 'Подвижная', 'sort' => 2]);

        $this->post(route('fastleo.resources.reorder', 'topics'), ['id' => $fixed->id, 'direction' => 'down'])->assertForbidden();
        $this->post(route('fastleo.resources.reorder', 'topics'), ['id' => $movable->id, 'direction' => 'up'])->assertRedirect();

        expect(topicOrder())->toBe(['Подвижная', 'Закреплённая']);
    });

    it('uses the update method when the policy has no reorder method', function (): void {
        Gate::policy(Topic::class, TopicUpdatePolicy::class);
        $foreign = Topic::factory()->create(['title' => 'Чужая', 'sort' => 1]);
        Topic::factory()->create(['title' => 'Своя', 'sort' => 2]);

        $this->post(route('fastleo.resources.reorder', 'topics'), ['id' => $foreign->id, 'direction' => 'down'])->assertForbidden();
        $this->get(route('fastleo.resources.index', 'topics'))->assertSee('data-reorder="up"', false);
    });

    it('does not reorder a readonly section or a record outside the section query', function (): void {
        useFixtureResources('Ordering');
        $hidden = Topic::factory()->create(['title' => 'Служебная', 'sort' => 1]);
        $open = Topic::factory()->create(['title' => 'Открытая', 'sort' => 2]);

        $this->post(route('fastleo.resources.reorder', 'readonly-topics'), ['id' => $open->id, 'direction' => 'up'])->assertForbidden();
        $this->post(route('fastleo.resources.reorder', 'open-topics'), ['id' => $hidden->id, 'direction' => 'down'])->assertNotFound();
        $this->post(route('fastleo.resources.reorder', 'open-topics'), ['id' => $open->id, 'direction' => 'up'])->assertRedirect();

        expect($hidden->fresh()?->sort)->toBe(1)->and($open->fresh()?->sort)->toBe(1);
    });
});

describe('order column as a form field', function (): void {
    beforeEach(function (): void {
        useFixtureResources('SortField');
    });

    it('puts a new record at the end when the order is left empty', function (): void {
        threeTopics();

        $this->post(route('fastleo.resources.store', 'sorted-topics'), ['title' => 'Г', 'sort' => ''])->assertSessionHasNoErrors();

        expect(topicOrder())->toBe(['А', 'Б', 'В', 'Г']);
    });

    it('puts an edited record at the end when its order is cleared instead of writing NULL', function (): void {
        [$first] = threeTopics();

        $this->put(route('fastleo.resources.update', ['sorted-topics', $first->id]), ['title' => 'А', 'sort' => ''])->assertSessionHasNoErrors();

        expect(topicOrder())->toBe(['Б', 'В', 'А']);
    });

    it('keeps the order typed by the admin', function (): void {
        [$first] = threeTopics();

        $this->put(route('fastleo.resources.update', ['sorted-topics', $first->id]), ['title' => 'А', 'sort' => '5'])->assertSessionHasNoErrors();

        expect($first->fresh()?->sort)->toBe(5);
    });
});
