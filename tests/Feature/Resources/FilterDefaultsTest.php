<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Filters\BooleanFilter;
use Fastleo\Filters\DateRangeFilter;
use Fastleo\Filters\SelectFilter;
use Fastleo\Tests\Fixtures\FilterDefaults\CategoryDefaultResource;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('FilterDefaults');
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Три статьи разных статусов с понятными заголовками.
 */
function articlesByStatus(): void
{
    Article::factory()->create(['title' => 'Опубликованная статья', 'status' => 'published']);
    Article::factory()->create(['title' => 'Черновая статья', 'status' => 'draft']);
    Article::factory()->create(['title' => 'Проверяемая статья', 'status' => 'review']);
}

describe('a default value', function (): void {
    it('filters the list when the address has no filter', function (): void {
        articlesByStatus();

        $this->get(route('fastleo.resources.index', 'status-default'))
            ->assertOk()
            ->assertSee('Опубликованная статья')
            ->assertDontSee('Черновая статья')
            ->assertDontSee('Проверяемая статья')
            ->assertSee('<option value="published" selected>', false);
    });

    it('is replaced by the value chosen in the address', function (): void {
        articlesByStatus();

        $this->get(route('fastleo.resources.index', ['resource' => 'status-default', 'filter' => ['status' => 'draft']]))
            ->assertOk()
            ->assertSee('Черновая статья')
            ->assertDontSee('Опубликованная статья');
    });

    it('is turned off by the empty value of "All"', function (): void {
        articlesByStatus();

        $this->get(route('fastleo.resources.index', ['resource' => 'status-default', 'filter' => ['status' => '']]))
            ->assertOk()
            ->assertSee('Опубликованная статья')
            ->assertSee('Черновая статья')
            ->assertSee('Проверяемая статья');
    });

    it('keeps the default when only the search is sent', function (): void {
        articlesByStatus();

        $this->get(route('fastleo.resources.index', ['resource' => 'status-default', 'q' => 'статья']))
            ->assertOk()
            ->assertSee('Опубликованная статья')
            ->assertDontSee('Черновая статья');
    });

    it('offers "Reset" only when the list differs from the one without parameters', function (): void {
        articlesByStatus();

        $plain = route('fastleo.resources.index', 'status-default');

        // Умолчание — это и есть «чистый» вид: сбрасывать нечего.
        $this->get($plain)->assertOk()->assertDontSee('Сбросить');
        // Выбрано «Все» или другое значение: кнопка ведёт назад, к умолчанию.
        $this->get(route('fastleo.resources.index', ['resource' => 'status-default', 'filter' => ['status' => '']]))->assertSee('Сбросить')->assertSee('href="'.$plain.'"', false);
        $this->get(route('fastleo.resources.index', ['resource' => 'status-default', 'filter' => ['status' => 'draft']]))->assertSee('Сбросить');
        // Поиск при умолчании — тоже отличие от чистого вида.
        $this->get(route('fastleo.resources.index', ['resource' => 'status-default', 'q' => 'статья']))->assertSee('Сбросить');
    });

    it('ignores a default that is not among the options', function (): void {
        expect(SelectFilter::make('status')->options(['a' => 'A'])->default('zzz')->value('zzz'))->toBeNull();
    });

    it('turns numbers, enums and booleans into the strings of an address', function (): void {
        expect(SelectFilter::make('status')->default(fn (): int => 5)->defaultInput())->toBe('5')
            ->and(SelectFilter::make('status')->default(ArticleStatus::Published)->defaultInput())->toBe('published')
            ->and(BooleanFilter::make('is_active')->default(true)->defaultInput())->toBe('1')
            ->and(BooleanFilter::make('is_active')->default(false)->defaultInput())->toBe('0')
            ->and(DateRangeFilter::make('created_at')->default(['from' => '2026-03-01', 'to' => null])->defaultInput())->toBe(['from' => '2026-03-01', 'to' => null])
            ->and(SelectFilter::make('status')->defaultInput())->toBeNull();
    });

    it('filters by a numeric key, as in "manager is me"', function (): void {
        $mine = Category::factory()->create();
        Article::factory()->create(['title' => 'Моя статья', 'category_id' => $mine->getKey()]);
        Article::factory()->create(['title' => 'Чужая статья', 'category_id' => Category::factory()->create()->getKey()]);
        CategoryDefaultResource::$categoryId = (int) $mine->getKey();

        $this->get(route('fastleo.resources.index', 'category-default'))
            ->assertOk()
            ->assertSee('Моя статья')
            ->assertDontSee('Чужая статья');
    });

    it('is evaluated for every request', function (): void {
        $this->travelTo(now()->setDate(2026, 3, 10)->setTime(12, 0));
        Article::factory()->create(['title' => 'Сегодняшняя', 'created_at' => now()]);
        Article::factory()->create(['title' => 'Позавчерашняя', 'created_at' => now()->subDays(2)]);

        $this->get(route('fastleo.resources.index', 'period-default'))
            ->assertOk()
            ->assertSee('Сегодняшняя')
            ->assertDontSee('Позавчерашняя');

        $this->travelTo(now()->addDays(3));

        $this->get(route('fastleo.resources.index', 'period-default'))
            ->assertOk()
            ->assertDontSee('Сегодняшняя');
    });

    it('is turned off by empty dates', function (): void {
        Article::factory()->create(['title' => 'Старая статья', 'created_at' => now()->subYear()]);

        $this->get(route('fastleo.resources.index', ['resource' => 'period-default', 'filter' => ['created_at' => ['from' => '', 'to' => '']]]))
            ->assertOk()
            ->assertSee('Старая статья');
    });
});

describe('where the default does not apply', function (): void {
    it('is used by the export of the same address', function (): void {
        articlesByStatus();

        $rows = exportedRows($this->get(route('fastleo.resources.export', ['resource' => 'status-default', 'format' => 'csv'])), 'csv');

        expect(collect($rows)->flatten()->all())->toContain('Опубликованная статья')->not->toContain('Черновая статья');
    });

    it('is not used by the global search', function (): void {
        articlesByStatus();

        $titles = collect($this->getJson(route('fastleo.search', ['q' => 'Черновая']))->assertOk()->json('groups'))->pluck('items')->flatten(1)->pluck('title');

        expect($titles)->toContain('Черновая статья');
    });
});
