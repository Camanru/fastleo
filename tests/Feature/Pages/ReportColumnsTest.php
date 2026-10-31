<?php

declare(strict_types=1);

use Fastleo\Dashboard;
use Fastleo\Fastleo;
use Fastleo\Page;
use Fastleo\Tests\Fixtures\ReportTiles\TilesPage;
use Fastleo\Widgets\DashboardGrid;
use Fastleo\Widgets\Stat;

beforeEach(function (): void {
    useFixtureResources('ReportTiles');
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('draws a report with six columns: the grid says so and a tile keeps its share', function (): void {
    $html = $this->get(route('fastleo.pages.show', 'tiles'))->assertOk()->assertSee('data-columns="6"', false)->getContent();

    expect(substr_count($html, 'data-cols="1"'))->toBe(6)
        ->and(substr_count($html, 'data-cols="6"'))->toBe(1);
});

it('keeps four columns by default and never lets a widget be wider than the grid', function (): void {
    $this->get(route('fastleo.pages.show', 'wide'))
        ->assertOk()
        ->assertSee('data-columns="4"', false)
        ->assertSee('data-cols="4"', false)
        ->assertDontSee('data-cols="6"', false);
});

it('knows only four and six columns', function (): void {
    expect(TilesPage::columns())->toBe(6);

    $odd = new class extends Page
    {
        protected static int $columns = 5;
    };

    expect($odd::columns())->toBe(4);
});

it('puts a widget that asks for six columns across the whole row of the four-column dashboard', function (): void {
    $dashboard = new class extends Dashboard
    {
        public function widgets(): array
        {
            return [Stat::make('Широкий')->value(1)->columnSpan(6)];
        }
    };

    $items = DashboardGrid::build($dashboard, null);

    expect($items[0]['cols'])->toBe(4);
});
