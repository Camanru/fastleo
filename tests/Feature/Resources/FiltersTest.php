<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Policies\HiddenCategoryPolicy;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\Topic;

beforeEach(function (): void {
    useFixtureResources('Filters');
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Заголовки статей на странице списка с фильтрами (по заголовку).
 *
 * @param  array<string, mixed>  $filter
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function filteredTitles(array $filter, array $query = []): array
{
    $response = test()->get(route('fastleo.resources.index', ['resource' => 'articles', 'filter' => $filter, ...$query]))->assertOk();

    return $response->viewData('records')->pluck('title')->sort()->values()->all();
}

it('narrows the list to the chosen option of a field', function (): void {
    Article::factory()->create(['title' => 'Черновик']);
    Article::factory()->published()->create(['title' => 'Готово']);

    expect(filteredTitles(['status' => 'published']))->toBe(['Готово']);
});

it('ignores a value that is not among the options', function (mixed $value): void {
    Article::factory()->count(2)->create();

    expect(filteredTitles(['status' => $value]))->toHaveCount(2);
})->with([
    'unknown option' => 'archived',
    'array' => [['published']],
    'empty' => '',
]);

it('finds records whose json list contains the chosen option', function (): void {
    Article::factory()->create(['title' => 'Обе', 'tags' => ['news', 'promo']]);
    Article::factory()->create(['title' => 'Новость', 'tags' => ['news']]);

    expect(filteredTitles(['tags' => 'promo']))->toBe(['Обе']);
});

it('narrows the list to a related record', function (): void {
    $news = Category::factory()->create();
    Article::factory()->create(['title' => 'В рубрике', 'category_id' => $news->id]);
    Article::factory()->create(['title' => 'Без рубрики']);

    expect(filteredTitles(['category_id' => (string) $news->id]))->toBe(['В рубрике'])
        ->and(filteredTitles(['category_id' => 'abc']))->toHaveCount(2);
});

it('shows published records for yes and the rest for no', function (): void {
    Article::factory()->published()->create(['title' => 'Да']);
    Article::factory()->create(['title' => 'Нет', 'is_published' => false]);

    expect(filteredTitles(['is_published' => '1']))->toBe(['Да'])
        ->and(filteredTitles(['is_published' => '0']))->toBe(['Нет'])
        ->and(filteredTitles(['is_published' => 'yes']))->toHaveCount(2);
});

it('keeps both ends of a date range', function (): void {
    Article::factory()->create(['title' => 'Раньше', 'published_on' => '2026-03-07']);
    Article::factory()->create(['title' => 'Начало', 'published_on' => '2026-03-08']);
    Article::factory()->create(['title' => 'Конец', 'published_on' => '2026-03-10']);
    Article::factory()->create(['title' => 'Позже', 'published_on' => '2026-03-11']);

    expect(filteredTitles(['published_on' => ['from' => '2026-03-08', 'to' => '2026-03-10']]))->toBe(['Конец', 'Начало'])
        ->and(filteredTitles(['published_on' => ['from' => '2026-03-10']]))->toBe(['Конец', 'Позже']);
});

it('counts the days of a date and time range in the admin time zone', function (): void {
    config(['fastleo.timezone' => 'Europe/Moscow', 'app.timezone' => 'UTC']);
    Article::factory()->create(['title' => 'Ночь 8 марта', 'starts_at' => '2026-03-07 22:30:00']);
    Article::factory()->create(['title' => 'Ночь 9 марта', 'starts_at' => '2026-03-08 21:30:00']);

    expect(filteredTitles(['starts_at' => ['from' => '2026-03-08', 'to' => '2026-03-08']]))->toBe(['Ночь 8 марта']);
});

it('filters a timestamp column without a field', function (): void {
    $this->travelTo('2026-03-08 12:00:00');
    Article::factory()->create(['title' => 'Сегодня']);
    $this->travelTo('2026-03-01 12:00:00');
    Article::factory()->create(['title' => 'Неделю назад']);

    expect(filteredTitles(['created_at' => ['from' => '2026-03-05']]))->toBe(['Сегодня']);
});

it('ignores dates that do not exist', function (mixed $range): void {
    Article::factory()->count(2)->create();

    expect(filteredTitles(['published_on' => $range]))->toHaveCount(2);
})->with([
    'february 30' => [['from' => '2026-02-30']],
    'other format' => [['from' => '08.03.2026']],
    'not a range' => '2026-03-08',
]);

it('keeps both ends of a number range', function (): void {
    foreach ([99 => 'Меньше', 100 => 'Начало', 500 => 'Конец', 501 => 'Больше'] as $views => $title) {
        Article::factory()->create(['title' => $title, 'views' => $views]);
    }

    expect(filteredTitles(['views' => ['from' => '100', 'to' => '500']]))->toBe(['Конец', 'Начало'])
        ->and(filteredTitles(['views' => ['from' => '500']]))->toBe(['Больше', 'Конец'])
        ->and(filteredTitles(['views' => ['to' => '99']]))->toBe(['Меньше'])
        ->and(filteredTitles(['views' => ['from' => '100,5', 'to' => '500.0']]))->toBe(['Конец']);
});

it('rounds a fractional end inwards for an integer column and keeps it for a decimal one', function (): void {
    foreach ([[99, '10.00'], [100, '10.50'], [101, '10.51'], [500, '11.00']] as [$views, $price]) {
        Article::factory()->create(['title' => "Просмотры $views", 'views' => $views, 'price' => $price]);
    }

    expect(filteredTitles(['views' => ['from' => '99.5', 'to' => '500.5']]))->toBe(['Просмотры 100', 'Просмотры 101', 'Просмотры 500'])
        ->and(filteredTitles(['views' => ['from' => '100.0', 'to' => '100.9']]))->toBe(['Просмотры 100'])
        ->and(filteredTitles(['price' => ['from' => '10.5', 'to' => '10.505']]))->toBe(['Просмотры 100']);
});

it('does not fail on a bound wider than the integer column', function (): void {
    Article::factory()->create(['title' => 'Обычная', 'views' => 10]);

    expect(filteredTitles(['views' => ['from' => '3000000000']]))->toBe([])
        ->and(filteredTitles(['views' => ['to' => '-3000000000']]))->toBe([])
        ->and(filteredTitles(['views' => ['from' => '-3000000000', 'to' => '99999999999']]))->toBe(['Обычная'])
        ->and(filteredTitles(['views' => ['from' => '2147483648.5']]))->toBe([]);
});

it('ignores a number range that is not a number', function (mixed $range): void {
    Article::factory()->count(2)->create();

    expect(filteredTitles(['views' => $range]))->toHaveCount(2);
})->with([
    'letters' => [['from' => 'abc', 'to' => '1e5']],
    'sql' => [['from' => '1 OR 1=1']],
    'too long' => [['from' => str_repeat('9', 40)]],
    'empty ends' => [['from' => '', 'to' => '']],
    'array ends' => [['from' => ['1'], 'to' => ['2']]],
    'not a range' => '100',
]);

it('shows the number range in the filter panel with the chosen ends', function (): void {
    $this->get(route('fastleo.resources.index', ['resource' => 'articles', 'filter' => ['views' => ['from' => '10', 'to' => '20']]]))
        ->assertOk()
        ->assertSee('data-filters-count>1</span>', false)
        ->assertSee('name="filter[views][from]" value="10"', false)
        ->assertSee('name="filter[views][to]" value="20"', false);
});

it('runs the own query of a filter', function (): void {
    Article::factory()->create(['title' => 'Популярная', 'views' => 5000]);
    Article::factory()->create(['title' => 'Тихая', 'views' => 10]);

    expect(filteredTitles(['popular' => 'yes']))->toBe(['Популярная']);
});

it('combines filters with each other and with the search', function (): void {
    Article::factory()->published()->create(['title' => 'Отчёт']);
    Article::factory()->published()->create(['title' => 'Новость']);
    Article::factory()->create(['title' => 'Отчёт черновик']);

    expect(filteredTitles(['status' => 'published', 'is_published' => '1'], ['q' => 'Отчёт']))->toBe(['Отчёт']);
});

it('shows the filter panel open with the chosen values and the number of active filters', function (): void {
    $category = Category::factory()->create(['title' => 'Новости']);

    $this->get(route('fastleo.resources.index', ['resource' => 'articles', 'filter' => ['status' => 'review', 'category_id' => (string) $category->id]]))
        ->assertOk()
        ->assertSee('data-filters-count>2</span>', false)
        ->assertSee('<option value="review" selected>На проверке</option>', false)
        ->assertSee('name="filter[category_id]"', false)
        ->assertSee('Ничего не найдено.');
});

it('does not show a filter panel for a section without filters', function (): void {
    useFixtureResources('Lists');

    $this->get(route('fastleo.resources.index', 'categories'))->assertOk()->assertDontSee('fastleo-filters');
});

it('narrows the list to records linked to a related record through a pivot table', function (): void {
    [$health, $sport] = Topic::factory()->count(2)->create()->all();
    $both = Article::factory()->create(['title' => 'Обе темы']);
    $both->topics()->attach([$health->id, $sport->id]);
    Article::factory()->create(['title' => 'Только спорт'])->topics()->attach($sport->id);
    Article::factory()->create(['title' => 'Без тем']);

    expect(filteredTitles(['topics' => (string) $health->id]))->toBe(['Обе темы'])
        ->and(filteredTitles(['topics' => (string) $sport->id]))->toBe(['Обе темы', 'Только спорт'])
        ->and(filteredTitles(['topics' => 'abc']))->toHaveCount(3)
        ->and(filteredTitles(['topics' => '999999']))->toBe([]);
});

it('shows the chosen topic in the filter panel', function (): void {
    $topic = Topic::factory()->create(['title' => 'Здоровье']);

    $this->get(route('fastleo.resources.index', ['resource' => 'articles', 'filter' => ['topics' => (string) $topic->id]]))
        ->assertOk()
        ->assertSee('filter[topics]', false)
        ->assertSee('Здоровье');
});

it('searches the options of a many-to-many filter on the server', function (): void {
    Topic::factory()->create(['title' => 'Здоровье']);
    Topic::factory()->create(['title' => 'Спорт']);

    $this->getJson(route('fastleo.resources.filter-options', ['articles', 'topics', 'q' => 'здор']))
        ->assertOk()
        ->assertJsonPath('options.0.label', 'Здоровье')
        ->assertJsonCount(1, 'options');
});

it('searches the options of a relation filter on the server', function (): void {
    Category::factory()->create(['title' => 'Новости']);
    Category::factory()->create(['title' => 'Акции']);

    $this->getJson(route('fastleo.resources.filter-options', ['articles', 'category_id', 'q' => 'нов']))
        ->assertOk()
        ->assertJsonPath('options.0.label', 'Новости')
        ->assertJsonCount(1, 'options');
});

it('offers server search only for a relation filter of a visible section', function (): void {
    $this->getJson(route('fastleo.resources.filter-options', ['articles', 'status']))->assertNotFound();
    $this->getJson(route('fastleo.resources.filter-options', ['articles', 'missing']))->assertNotFound();

    Gate::policy(Article::class, HiddenCategoryPolicy::class);
    $this->getJson(route('fastleo.resources.filter-options', ['articles', 'category_id']))->assertForbidden();
});

it('shows a tree as a flat list without manual order while a filter is on', function (): void {
    $parent = Category::factory()->create(['code' => 'a']);
    Category::factory()->create(['code' => 'b', 'parent_id' => $parent->id]);

    $tree = $this->get(route('fastleo.resources.index', 'categories'))->viewData('list');
    $filtered = $this->get(route('fastleo.resources.index', ['resource' => 'categories', 'filter' => ['code' => 'b']]))->viewData('list');

    expect($tree->isTree())->toBeTrue()
        ->and($filtered->isTree())->toBeFalse()
        ->and($filtered->isManualOrder())->toBeFalse()
        ->and($filtered->paginate()->total())->toBe(1);
});
