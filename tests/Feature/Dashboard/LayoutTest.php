<?php

declare(strict_types=1);

use Fastleo\Dashboard as BaseDashboard;
use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\DashboardLayout\Dashboard;
use Fastleo\Widgets\DashboardGrid;
use Fastleo\Widgets\DashboardLayout;
use Fastleo\Widgets\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    useFixtureResources('DashboardLayout');
    Dashboard::$calls = 0;
    $this->me = admin(['name' => 'Анна']);
    $this->actingAs($this->me, Fastleo::GUARD);
});

/**
 * Сохранить раскладку: список [ключ, cols, rows, hidden].
 *
 * @param  list<array{string, int, int, bool}>  $items
 */
function putLayout(array $items): TestResponse
{
    return test()->putJson(route('fastleo.dashboard.layout.save'), [
        'widgets' => array_map(fn (array $item): array => ['key' => $item[0], 'cols' => $item[1], 'rows' => $item[2], 'hidden' => $item[3]], $items),
    ]);
}

describe('size and keys', function (): void {
    it('prints the size from the code on the card', function (): void {
        $html = $this->get(route('fastleo.dashboard'))->getContent();

        expect($html)->toContain('data-key="stat-zaiavki"')->toContain('data-cols="3"')->toContain('data-rows="2"')
            ->and($html)->toContain('data-key="counter"');
    });

    it('limits columnSpan and rowSpan to 1–4', function (): void {
        expect(Stat::make('a')->rowSpan(9)->getRows())->toBe(4)
            ->and(Stat::make('a')->rowSpan(0)->getRows())->toBe(1)
            ->and(Stat::make('a')->getRows())->toBe(1);
    });

    it('gives widgets with the same title different keys', function (): void {
        expect(array_keys(DashboardGrid::keyed(new Dashboard)))->toBe(['stat-zaiavki', 'stat-zaiavki-2', 'counter', 'stat-tolko-anne']);
    });

    it('accepts only a safe key', function (): void {
        expect(Stat::make('a')->key('leads-today')->getKey())->toBe('leads-today');

        Stat::make('a')->key('Leads today');
    })->throws(InvalidArgumentException::class);
});

describe('saved layout', function (): void {
    it('applies the order, the size and the hiding of the administrator', function (): void {
        putLayout([['counter', 2, 3, false], ['stat-zaiavki', 1, 1, true]])->assertNoContent();

        $items = DashboardGrid::build(new Dashboard, $this->me);

        expect(array_column($items, 'key'))->toBe(['counter', 'stat-zaiavki', 'stat-zaiavki-2', 'stat-tolko-anne'])
            ->and($items[0])->toMatchArray(['cols' => 2, 'rows' => 3, 'hidden' => false])
            ->and($items[1])->toMatchArray(['hidden' => true])
            ->and($items[2])->toMatchArray(['cols' => 1, 'rows' => 1, 'hidden' => false]);

        $this->get(route('fastleo.dashboard'))->assertOk()->assertSee('data-hidden="true"', false)->assertSee('data-cols="2"', false);
    });

    it('does not count a hidden widget', function (): void {
        putLayout([['counter', 1, 1, true]])->assertNoContent();

        $this->get(route('fastleo.dashboard'))->assertOk()->assertSee('data-widget-hidden', false);

        expect(Dashboard::$calls)->toBe(0);
    });

    it('puts a new widget of the code after the saved ones and drops a vanished key', function (): void {
        DashboardLayout::query()->create(['user_id' => (string) $this->me->getKey(), 'layout' => [
            ['key' => 'gone-widget', 'cols' => 1, 'rows' => 1, 'hidden' => false],
            ['key' => 'stat-zaiavki-2', 'cols' => 4, 'rows' => 1, 'hidden' => false],
        ]]);

        expect(array_column(DashboardGrid::build(new Dashboard, $this->me), 'key'))->toBe(['stat-zaiavki-2', 'stat-zaiavki', 'counter', 'stat-tolko-anne']);
    });

    it('keeps the layout of one administrator away from another', function (): void {
        putLayout([['counter', 4, 4, true]])->assertNoContent();

        $other = admin(['name' => 'Борис']);

        expect(array_column(array_filter(DashboardGrid::build(new Dashboard, $other), fn (array $item): bool => $item['hidden']), 'key'))->toBe([])
            ->and(DashboardLayout::query()->count())->toBe(1);
    });

    it('returns to the layout of the code after the reset', function (): void {
        putLayout([['counter', 4, 4, true]])->assertNoContent();

        $this->delete(route('fastleo.dashboard.layout.reset'))->assertRedirect(route('fastleo.dashboard'));

        expect(DashboardLayout::query()->count())->toBe(0)
            ->and(DashboardGrid::build(new Dashboard, $this->me)[0]['key'])->toBe('stat-zaiavki');
    });

    it('replaces the layout on the second save instead of adding a record', function (): void {
        putLayout([['counter', 1, 1, false]])->assertNoContent();
        putLayout([['counter', 2, 2, false]])->assertNoContent();

        expect(DashboardLayout::query()->count())->toBe(1)
            ->and(DashboardLayout::query()->first()?->layout)->toBe([['key' => 'counter', 'cols' => 2, 'rows' => 2, 'hidden' => false]]);
    });
});

describe('validation', function (): void {
    it('rejects a size outside 1–4', function (): void {
        putLayout([['counter', 5, 1, false]])->assertJsonValidationErrors('widgets.0.cols');
        putLayout([['counter', 1, 0, false]])->assertJsonValidationErrors('widgets.0.rows');
    });

    it('rejects a bad key, extra fields and a body that is not a list', function (): void {
        putLayout([['Bad Key!', 1, 1, false]])->assertJsonValidationErrors('widgets.0.key');
        $this->putJson(route('fastleo.dashboard.layout.save'), ['widgets' => [['key' => 'counter', 'cols' => 1, 'rows' => 1, 'hidden' => false, 'user_id' => '99']]])->assertJsonValidationErrors('widgets.0');
        $this->putJson(route('fastleo.dashboard.layout.save'), ['widgets' => 'x'])->assertJsonValidationErrors('widgets');
        $this->putJson(route('fastleo.dashboard.layout.save'), [])->assertJsonValidationErrors('widgets');

        expect(DashboardLayout::query()->count())->toBe(0);
    });

    it('sends a guest to the login page', function (): void {
        auth(Fastleo::GUARD)->logout();
        $this->flushSession();

        $this->putJson(route('fastleo.dashboard.layout.save'), ['widgets' => []])->assertUnauthorized();
        expect(DashboardLayout::query()->count())->toBe(0);
    });
});

describe('keys and a missing table', function (): void {
    it('keeps a given key and gives the suffix to the key from the title', function (): void {
        $long = str_repeat('a', 100);
        $dashboard = new class($long) extends BaseDashboard
        {
            public function __construct(private readonly string $long) {}

            public function widgets(): array
            {
                return [
                    Stat::make('Заявки'),
                    Stat::make('Другое')->key('stat-zaiavki'),
                    Stat::make('Длинный')->key($this->long),
                ];
            }
        };

        expect(array_keys(DashboardGrid::keyed($dashboard)))->toBe(['stat-zaiavki-2', 'stat-zaiavki', $long]);
    });

    it('shows the layout of the code when the table is not migrated yet', function (): void {
        Schema::drop('fastleo_dashboard_layouts');

        $this->get(route('fastleo.dashboard'))->assertOk()->assertSee('data-cols="3"', false);
    })->skip(fn (): bool => in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true), 'DROP TABLE в MySQL неявно закрывает транзакцию теста, и точка сохранения теряется');
});
