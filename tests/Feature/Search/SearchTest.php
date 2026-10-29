<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Policies\HiddenCategoryPolicy;
use Fastleo\Tests\Fixtures\Policies\SecretCategoryPolicy;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Category;
use Workbench\App\Models\Topic;

beforeEach(function (): void {
    useFixtureResources('Search');
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Группы результатов поиска.
 *
 * @return list<array{label: string, items: list<array{title: string, hint: string, url: string}>}>
 */
function searchGroups(mixed $test, string $query): array
{
    return $test->getJson(route('fastleo.search', ['q' => $query]))->assertOk()->json('groups');
}

describe('records', function (): void {
    it('finds records by searchable fields and links to the edit form', function (): void {
        $category = Category::factory()->create(['title' => 'Новости компании', 'code' => 'company']);
        Category::factory()->create(['title' => 'Акции', 'code' => 'sales']);

        $groups = searchGroups($this, 'компан');

        expect($groups)->toHaveCount(1)
            ->and($groups[0]['label'])->toBe('Категории')
            ->and($groups[0]['items'])->toBe([[
                'title' => 'Новости компании',
                'hint' => 'Категория',
                'url' => route('fastleo.resources.edit', ['categories', $category->id]),
            ]]);

        expect(searchGroups($this, 'SALES')[0]['items'][0]['title'])->toBe('Акции');
    });

    it('searches the percent and underscore as ordinary characters', function (): void {
        Category::factory()->create(['title' => 'Скидка 100%']);
        Category::factory()->create(['title' => 'Скидка 1000']);
        Category::factory()->create(['title' => 'a_b']);
        Category::factory()->create(['title' => 'axb']);

        expect(array_column(searchGroups($this, '0%')[0]['items'], 'title'))->toBe(['Скидка 100%'])
            ->and(array_column(searchGroups($this, 'a_b')[0]['items'], 'title'))->toBe(['a_b']);
    });

    it('returns at most five records per section, newest first', function (): void {
        foreach (range(1, 8) as $number) {
            Category::factory()->create(['title' => 'Раздел '.$number]);
        }

        $titles = array_column(searchGroups($this, 'Раздел')[0]['items'], 'title');

        expect($titles)->toBe(['Раздел 8', 'Раздел 7', 'Раздел 6', 'Раздел 5', 'Раздел 4']);
    });

    it('does not look into a section that opted out of the global search', function (): void {
        Topic::factory()->create(['title' => 'Секретная тема']);
        Category::factory()->create(['title' => 'Секретная категория']);

        $labels = array_column(searchGroups($this, 'Секретная'), 'label');

        expect($labels)->toBe(['Категории']);
        // В самом разделе поиск по списку при этом работает.
        $this->get(route('fastleo.resources.index', ['topics', 'q' => 'Секретная']))->assertOk()->assertSee('Секретная тема');
    });

    it('finds sections by name', function (): void {
        $groups = searchGroups($this, 'катег');

        expect($groups[0]['label'])->toBe('Разделы')
            ->and($groups[0]['items'][0])->toMatchArray(['title' => 'Категории', 'url' => route('fastleo.resources.index', 'categories')]);
    });
});

describe('access', function (): void {
    it('skips sections the admin may not open', function (): void {
        Gate::policy(Category::class, HiddenCategoryPolicy::class);
        Category::factory()->create(['title' => 'Скрытая категория']);

        expect(searchGroups($this, 'Скрытая'))->toBe([]);
    });

    it('skips records the policy hides and links read-only ones to the view page', function (): void {
        Gate::policy(Category::class, SecretCategoryPolicy::class);
        Category::factory()->create(['title' => 'Секрет']);
        $open = Category::factory()->create(['title' => 'Открыто']);
        $closed = Category::factory()->create(['title' => 'Закрыто']);

        $items = collect(searchGroups($this, 'кр'))->firstWhere('label', 'Категории')['items'];
        $byTitle = array_column($items, 'url', 'title');

        expect($byTitle)->toBe([
            'Закрыто' => route('fastleo.resources.show', ['categories', $closed->id]),
            'Открыто' => route('fastleo.resources.edit', ['categories', $open->id]),
        ]);
    });

    it('is not available without signing in', function (): void {
        auth(Fastleo::GUARD)->logout();

        $this->getJson(route('fastleo.search', ['q' => 'ab']))->assertUnauthorized();
    });
});

describe('query', function (): void {
    it('answers nothing to a short query and rejects a wrong one', function (mixed $query, int $status): void {
        $this->getJson(route('fastleo.search', ['q' => $query]))->assertStatus($status);
    })->with([
        'one character' => ['а', 200],
        'spaces only' => ['   ', 200],
        'empty' => ['', 200],
        'array' => [['a' => 'b'], 422],
        'too long' => [str_repeat('а', 101), 422],
        'limit' => [str_repeat('а', 100), 200],
    ]);

    it('answers nothing to a request without a query', function (): void {
        $this->getJson(route('fastleo.search'))->assertOk()->assertExactJson(['groups' => []]);
    });

    it('ignores sorting and filters from the address', function (): void {
        Category::factory()->create(['title' => 'Найдись']);

        $this->getJson(route('fastleo.search', ['q' => 'Найдись', 'sort' => 'password', 'filter' => ['x' => 'y'], 'parent' => '1']))
            ->assertOk()
            ->assertJsonPath('groups.0.items.0.title', 'Найдись');
    });

    it('limits the number of requests per minute', function (): void {
        foreach (range(1, 60) as $attempt) {
            $this->getJson(route('fastleo.search', ['q' => 'ab']))->assertOk();
        }

        $this->getJson(route('fastleo.search', ['q' => 'ab']))->assertStatus(429);
    });
});
