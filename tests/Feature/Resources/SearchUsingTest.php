<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\Text;
use Fastleo\Mcp\FastleoServer;
use Fastleo\Mcp\Tools\ListRecords;
use Fastleo\Tests\Fixtures\SearchUsing\ArticleResource;
use Fastleo\Tests\Fixtures\SearchUsingTranslatable\PageResource;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\Page;

beforeEach(function (): void {
    useFixtureResources('SearchUsing');
    ArticleResource::$hiddenCalls = 0;
    $this->actingAs(admin(), Fastleo::GUARD);
    $this->client = Article::factory()->create(['title' => '79001234567']);
    Article::factory()->create(['title' => '79990000000', 'category_id' => Category::factory()->create(['title' => 'Срочные'])->id]);
});

/**
 * Телефоны статей на странице списка по строке поиска.
 *
 * @return list<string>
 */
function searchedTitles(string $term): array
{
    return test()->get(route('fastleo.resources.index', ['resource' => 'articles', 'q' => $term]))
        ->assertOk()->viewData('records')->pluck('title')->sort()->values()->all();
}

it('finds a record by a formatted number through the field hook', function (string $term): void {
    expect(searchedTitles($term))->toBe(['79001234567']);
})->with(['8 (900) 123-45-67', '+7 900 123-45-67', '123-45']);

it('falls back to the plain search when the hook decides so, and searches through a relation', function (): void {
    expect(searchedTitles('7999'))->toBe(['79990000000'])
        ->and(searchedTitles('срочн'))->toBe(['79990000000'])
        ->and(searchedTitles('нет такого'))->toBe([])
        ->and(ArticleResource::$hiddenCalls)->toBe(0);
});

it('uses the hook in the global search and the export', function (): void {
    $this->getJson(route('fastleo.search', ['q' => '8 900 123 45 67']))->assertOk()->assertJsonFragment(['title' => '79001234567']);

    $csv = $this->get(route('fastleo.resources.export', ['resource' => 'articles', 'q' => '8 (900) 123-45-67']))->streamedContent();

    expect($csv)->toContain('79001234567')->not->toContain('79990000000')
        ->and(ArticleResource::$hiddenCalls)->toBe(0);
});

it('keeps a field searchable after a hook is set and forgets the hook when searching is turned off', function (): void {
    $field = Text::make('title')->searchUsing(fn (): null => null);

    expect($field->isSearchable())->toBeTrue()
        ->and($field->searchCallback())->toBeInstanceOf(Closure::class)
        ->and($field->searchable(false)->searchCallback())->toBeNull()
        ->and(Text::make('title')->searchable()->searchCallback())->toBeNull();
});

it('refuses a hook on a field that cannot be searched', function (): void {
    expect(fn (): Text => Text::make('total')->fromQuery()->searchUsing(fn (): null => null))->toThrow(LogicException::class);
});

it('finds nothing, not everything, when the only hook adds no condition', function (): void {
    useFixtureResources('SearchUsingOnly');

    expect(searchedTitles('79'))->toBe([])
        ->and(searchedTitles('7900'))->toBe(['79001234567']);
});

it('uses the hook in the MCP list of records', function (): void {
    FastleoServer::actingAs(admin(), Fastleo::GUARD)->tool(ListRecords::class, ['resource' => 'articles', 'q' => '8 (900) 123-45-67'])
        ->assertSee('79001234567')->assertDontSee('79990000000');
});

it('calls the hook of a translatable field once for every language with the path of the column', function (): void {
    useFixtureResources('SearchUsingTranslatable');
    config(['fastleo.locales' => ['ru', 'en']]);
    PageResource::$columns = [];
    Page::factory()->create(['title' => ['ru' => 'Врачи', 'en' => 'Doctors']]);
    Page::factory()->create(['title' => ['ru' => 'Цены', 'en' => 'Prices']]);

    $titles = test()->get(route('fastleo.resources.index', ['resource' => 'site-pages', 'q' => 'Doctors']))
        ->assertOk()->viewData('records')->pluck('title')->all();

    expect($titles)->toHaveCount(1)
        ->and(PageResource::$columns)->toBe(['pages.title->ru', 'pages.title->en']);
});
