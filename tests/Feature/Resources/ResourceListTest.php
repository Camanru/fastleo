<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('Lists');
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Названия категорий на странице списка по порядку.
 *
 * @param  array<string, string>  $query
 * @return list<string>
 */
function listedTitles(array $query = []): array
{
    $response = test()->get(route('fastleo.resources.index', ['resource' => 'categories', ...$query]))->assertOk();

    return $response->viewData('records')->pluck('title')->all();
}

it('shows the same buttons of a record in the table and in the cards for the phone', function (): void {
    $category = Category::factory()->create();
    $html = $this->get(route('fastleo.resources.index', 'categories'))->assertOk()->getContent();

    expect(substr_count((string) $html, 'href="'.route('fastleo.resources.edit', ['categories', $category->id]).'"'))->toBeGreaterThanOrEqual(2)
        ->and(substr_count((string) $html, 'action="'.route('fastleo.resources.destroy', ['categories', $category->id]).'"'))->toBe(2)
        ->and(substr_count((string) $html, 'data-confirm='))->toBeGreaterThanOrEqual(2);
});

it('finds records by a substring of a searchable field regardless of letter case', function (): void {
    Category::factory()->create(['title' => 'Новости компании']);
    Category::factory()->create(['title' => 'Акции']);

    expect(listedTitles(['q' => 'КОМПАН']))->toBe(['Новости компании'])
        ->and(listedTitles(['q' => 'новости']))->toBe(['Новости компании'])
        ->and(listedTitles(['q' => 'акЦИИ']))->toBe(['Акции']);
});

it('finds records by a searchable field', function (): void {
    Category::factory()->create(['title' => 'News archive', 'code' => 'n1']);
    Category::factory()->create(['title' => 'Sales', 'code' => 'promo-news']);
    Category::factory()->create(['title' => 'Other', 'code' => 'o1']);

    expect(listedTitles(['q' => 'NEWS', 'sort' => 'title']))->toBe(['News archive', 'Sales']);
});

it('treats LIKE wildcards in the search as ordinary characters', function (string $search, string $expected): void {
    Category::factory()->create(['title' => '50% скидка']);
    Category::factory()->create(['title' => '500 рублей']);
    Category::factory()->create(['title' => 'код_1']);
    Category::factory()->create(['title' => 'кодA1']);
    Category::factory()->create(['title' => 'знак !']);

    expect(listedTitles(['q' => $search]))->toBe([$expected]);
})->with([
    'percent' => ['50%', '50% скидка'],
    'underscore' => ['код_', 'код_1'],
    'escape character' => ['!', 'знак !'],
]);

it('ignores a search sent as an array', function (): void {
    Category::factory()->count(2)->create();

    expect(listedTitles(['q' => ['x']]))->toHaveCount(2);
});

it('sorts by a sortable column in both directions', function (): void {
    foreach (['Бета', 'Альфа', 'Гамма'] as $title) {
        Category::factory()->create(['title' => $title]);
    }

    expect(listedTitles(['sort' => 'title']))->toBe(['Альфа', 'Бета', 'Гамма'])
        ->and(listedTitles(['sort' => 'title', 'direction' => 'desc']))->toBe(['Гамма', 'Бета', 'Альфа']);
});

it('shows the newest records first by default', function (): void {
    foreach (['Первая', 'Вторая', 'Третья'] as $title) {
        Category::factory()->create(['title' => $title]);
    }

    expect(listedTitles())->toBe(['Третья', 'Вторая', 'Первая']);
});

it('ignores sorting by a column that is not sortable', function (string $column): void {
    foreach (['Первая', 'Вторая'] as $title) {
        Category::factory()->create(['title' => $title]);
    }

    expect(listedTitles(['sort' => $column]))->toBe(['Вторая', 'Первая']);
})->with([
    'column outside the resource' => ['internal_code'],
    'sql injection' => ['title; drop table categories'],
]);

it('splits the list into pages and shows all records on request up to the limit', function (): void {
    config(['fastleo.list.per_page' => 2, 'fastleo.list.max_all' => 3]);
    Category::factory()->count(4)->create();

    expect(listedTitles())->toHaveCount(2)
        ->and(listedTitles(['per_page' => 'all']))->toHaveCount(3);
    $this->get(route('fastleo.resources.index', 'categories'))
        ->assertSee('Записи 1–2 из 4')
        ->assertSee('per_page=all', false);
});

it('tells when the search finds nothing', function (): void {
    Category::factory()->create(['title' => 'Новости']);

    $this->get(route('fastleo.resources.index', ['resource' => 'categories', 'q' => 'нет такого']))
        ->assertOk()
        ->assertSee('Ничего не найдено.');
});

it('tells when the section is empty', function (): void {
    $this->get(route('fastleo.resources.index', 'categories'))
        ->assertOk()
        ->assertSee('Записей пока нет.');
});
