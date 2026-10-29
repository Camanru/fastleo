<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Workbench\App\Models\Article;

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
