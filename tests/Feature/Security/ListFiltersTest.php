<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\HiddenFilter\ArticleResource as HiddenFilterArticleResource;
use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('Filters');
    $this->actingAs(admin(), Fastleo::GUARD);
    Article::factory()->create(['views' => 10]);
    Article::factory()->create(['views' => 20]);
});

it('does not filter by a column that has no filter in the resource', function (): void {
    $response = $this->get(route('fastleo.resources.index', ['resource' => 'articles', 'filter' => ['views' => '10', 'id' => '1']]))->assertOk();

    expect($response->viewData('records'))->toHaveCount(2)
        ->and($response->viewData('list')->filters)->toBe([]);
});

it('answers malformed filter input with the whole list instead of an error', function (mixed $filter): void {
    $response = $this->get(route('fastleo.resources.index', ['resource' => 'articles', 'filter' => $filter]))->assertOk();

    expect($response->viewData('records'))->toHaveCount(2);
})->with([
    'filter as a string' => 'status',
    'nested arrays' => [['status' => [['published']], 'category_id' => ['1'], 'published_on' => ['from' => ['x']]]],
    'sql in a value' => [['status' => "published' OR '1'='1", 'category_id' => '1 OR 1=1']],
    'very long value' => [['status' => str_repeat('a', 10000)]],
]);

describe('a field hidden from the administrator', function (): void {
    beforeEach(function (): void {
        useFixtureResources('HiddenFilter');
    });

    it('cannot be used to guess its values through the filter of the list', function (): void {
        $category = Category::factory()->create();
        Article::factory()->create(['category_id' => $category->id]);

        $response = $this->get(route('fastleo.resources.index', ['resource' => 'articles', 'filter' => ['category_id' => (string) $category->id]]))->assertOk();

        expect($response->viewData('records'))->toHaveCount(3)
            ->and(array_keys($response->viewData('resource')->listFilters()))->toBe(['status']);
    });

    it('does not offer its options to the search of the filter', function (): void {
        $this->getJson(route('fastleo.resources.filter-options', ['articles', 'category_id']))->assertNotFound();
    });
});

describe('describing a resource without an administrator', function (): void {
    beforeEach(function (): void {
        useFixtureResources('HiddenFilter');
        HiddenFilterArticleResource::$hiddenAsked = 0;
    });

    it('lists all filters without asking whether their fields are hidden', function (): void {
        $resource = Fastleo::resources()->find('articles');

        expect(array_keys($resource->allFilters()))->toBe(['status', 'category_id'])
            ->and(HiddenFilterArticleResource::$hiddenAsked)->toBe(0)
            ->and(array_keys($resource->listFilters()))->toBe(['status'])
            ->and(HiddenFilterArticleResource::$hiddenAsked)->toBe(1);
    });

    it('asks whether a field is hidden once for the whole request', function (): void {
        $resource = Fastleo::resources()->find('articles');

        $resource->listFilters();
        $resource->listFilters();

        expect(HiddenFilterArticleResource::$hiddenAsked)->toBe(1);
    });

    it('shows all filters of the resource in inspect', function (): void {
        Artisan::call('fastleo:inspect articles --json');
        $inspected = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect(array_column($inspected['filters'], 'name'))->toBe(['status', 'category_id']);
    });
});
