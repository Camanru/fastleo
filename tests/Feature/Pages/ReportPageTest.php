<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Fastleo\Fastleo;
use Fastleo\Reports\Choice;
use Fastleo\Reports\Filters;
use Fastleo\Reports\Period;
use Fastleo\Reports\PeriodValue;
use Fastleo\Widgets\Rows;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    useFixtureResources('Reports');
    $this->owner = admin(['email' => 'owner@example.com']);
    $this->actingAs($this->owner, Fastleo::GUARD);
    $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));
});

/**
 * Статья, созданная в указанный день.
 *
 * @param  array<string, mixed>  $attributes
 */
function articleOn(string $day, array $attributes = []): Article
{
    return Article::factory()->create(['status' => 'draft', 'views' => 10, 'created_at' => $day.' 10:00:00', ...$attributes]);
}

/**
 * Страница отчёта по статьям с фильтрами из адреса.
 *
 * @param  array<string, mixed>  $filter
 */
function reportPage(array $filter = []): TestResponse
{
    return test()->get(route('fastleo.pages.show', ['page' => 'articles', ...($filter === [] ? [] : ['filter' => $filter])]));
}

/**
 * Число в плитке «Статей».
 */
function statValue(TestResponse $response): string
{
    preg_match('~data-stat-value>([^<]*)<~', (string) $response->getContent(), $matches);

    return trim($matches[1] ?? '');
}

describe('the period', function (): void {
    it('is the month so far by default and counts only records inside it', function (): void {
        articleOn('2026-03-02');
        articleOn('2026-03-15');
        articleOn('2026-02-20');

        $response = reportPage()->assertOk();

        expect(statValue($response))->toBe('2')
            ->and($response->getContent())->toContain('value="2026-03-01"')->toContain('value="2026-03-15"');
    });

    it('takes the dates chosen in the address, swapping them when reversed', function (): void {
        articleOn('2026-03-02');
        articleOn('2026-02-20');
        articleOn('2026-01-05');

        expect(statValue(reportPage(['period' => ['from' => '2026-02-01', 'to' => '2026-03-31']])))->toBe('2')
            ->and(statValue(reportPage(['period' => ['from' => '2026-03-31', 'to' => '2026-02-01']])))->toBe('2');
    });

    it('is open when both dates are cleared and ignores a date that does not exist', function (): void {
        articleOn('2026-03-02');
        articleOn('2025-12-20');

        expect(statValue(reportPage(['period' => ['from' => '', 'to' => '']])))->toBe('2')
            ->and(statValue(reportPage(['period' => ['from' => '2026-02-30', 'to' => 'вчера']])))->toBe('2')
            ->and(statValue(reportPage(['period' => 'oops'])))->toBe('2');
    });

    it('draws the preset periods as links and marks the current one', function (): void {
        $this->get(route('fastleo.pages.show', 'articles'))
            ->assertOk()
            ->assertSee('Сегодня')
            ->assertSee('Квартал')
            ->assertSee('7 дней')
            ->assertSee('aria-current="true"', false)
            ->assertSee('filter%5Bperiod%5D%5Bfrom%5D=2026-03-09', false);
    });

    it('marks only the first preset when two of them are the same days', function (): void {
        // Первый месяц квартала: «Месяц» и «Квартал» — один и тот же период.
        $this->travelTo(now()->setDate(2026, 4, 10));

        $content = (string) $this->get(route('fastleo.pages.show', 'articles'))->assertOk()->getContent();

        expect(substr_count($content, 'aria-current="true"'))->toBe(1);
    });

    it('keeps the other filters in the preset links', function (): void {
        $this->get(route('fastleo.pages.show', ['page' => 'articles', 'filter' => ['status' => 'draft']]))
            ->assertOk()
            ->assertSee('filter%5Bstatus%5D=draft', false);
    });

    it('counts days in the time zone of the admin panel', function (): void {
        config(['fastleo.timezone' => 'Asia/Yekaterinburg']);
        $period = new Period('period')->resolve(['from' => '2026-03-16', 'to' => '2026-03-16'], true);

        expect($period->from?->setTimezone('UTC')->toDateTimeString())->toBe('2026-03-15 19:00:00')
            ->and($period->to?->setTimezone('UTC')->toDateTimeString())->toBe('2026-03-16 18:59:59');
    });

    it('limits the query by the days of the admin time zone, not of the site', function (): void {
        config(['fastleo.timezone' => 'Asia/Yekaterinburg']);
        Article::factory()->create(['status' => 'draft', 'created_at' => '2026-03-15 20:30:00']); // 16 марта 01:30 в Екатеринбурге
        Article::factory()->create(['status' => 'draft', 'created_at' => '2026-03-15 18:30:00']); // 15 марта 23:30 в Екатеринбурге

        expect(statValue(reportPage(['period' => ['from' => '2026-03-16', 'to' => '2026-03-16']])))->toBe('1');
    });

    it('lists the days of the period and caps them at a year', function (): void {
        $period = new Period('period')->resolve(['from' => '2026-03-01', 'to' => '2026-03-03'], true);
        $long = new Period('period')->resolve(['from' => '2020-01-01', 'to' => '2026-01-01'], true);

        expect($period->days())->toBe(['2026-03-01', '2026-03-02', '2026-03-03'])
            ->and($long->days())->toHaveCount(366)
            ->and(new Period('period')->resolve(['from' => '2026-03-01'], true)->days())->toBe([]);
    });

    it('knows the preset ranges', function (): void {
        $ranges = new Period('period')->presetRanges();

        expect($ranges)->toBe([
            'today' => ['2026-03-15', '2026-03-15'],
            'yesterday' => ['2026-03-14', '2026-03-14'],
            'week' => ['2026-03-09', '2026-03-15'],
            'month' => ['2026-03-01', '2026-03-15'],
            'quarter' => ['2026-01-01', '2026-03-15'],
        ])->and(new Period('period')->presets(['month', 'bogus', 'today'])->presetRanges())->toHaveKeys(['month', 'today'])->not->toHaveKey('week');
    });

    it('uses a default given as a range or by a closure', function (): void {
        $yesterdayToday = new Period('period')->default(fn (): array => ['from' => '2026-03-14', 'to' => '2026-03-15'])->resolve(null, false);

        expect($yesterdayToday->fromDate())->toBe('2026-03-14')->and($yesterdayToday->toDate())->toBe('2026-03-15')
            ->and(new Period('period')->default('yesterday')->resolve(null, false)->fromDate())->toBe('2026-03-14')
            ->and(new Period('period')->default('nonsense')->resolve(null, false)->fromDate())->toBe('2026-03-15');
    });
});

describe('the list filter', function (): void {
    it('narrows the report to the chosen value and shows it selected', function (): void {
        articleOn('2026-03-02', ['status' => 'draft']);
        articleOn('2026-03-03', ['status' => 'published']);
        articleOn('2026-03-04', ['status' => 'published']);

        $response = reportPage(['status' => 'published']);

        expect(statValue($response))->toBe('2')
            ->and(statValue(reportPage(['status' => ''])))->toBe('3');

        $response->assertSee('<option value="published" selected>', false);
    });

    it('drops a value that is not among the options', function (): void {
        articleOn('2026-03-02');

        expect(statValue(reportPage(['status' => 'archived'])))->toBe('1')
            ->and(statValue(reportPage(['status' => ['draft']])))->toBe('1');
    });

    it('uses its default until the admin chooses "All"', function (): void {
        $choice = Choice::make('manager', 'Менеджер')->options([1 => 'Анна', 2 => 'Иван'])->default(fn (): int => 2);

        expect($choice->resolve(null, false))->toBe('2')
            ->and($choice->resolve(null, true))->toBeNull()
            ->and($choice->resolve('1', true))->toBe('1')
            ->and($choice->resolve('7', true))->toBeNull();
    });
});

describe('the widgets', function (): void {
    it('draws the table with the totals row', function (): void {
        articleOn('2026-03-02', ['status' => 'draft', 'views' => 10]);
        articleOn('2026-03-03', ['status' => 'published', 'views' => 5]);

        reportPage()
            ->assertOk()
            ->assertSeeInOrder(['Статус', 'Статей', 'Просмотров', 'Черновик', 'Опубликовано', 'Итого'])
            ->assertSee('<tfoot>', false);
    });

    it('draws a chart with one line for each series', function (): void {
        articleOn('2026-03-02');

        $content = (string) reportPage(['period' => ['from' => '2026-03-01', 'to' => '2026-03-03']])->assertOk()->getContent();
        preg_match('~data-chart="([^"]+)"~', $content, $matches);
        $chart = json_decode(html_entity_decode($matches[1]), true, flags: JSON_THROW_ON_ERROR);

        expect($chart['labels'])->toBe(['2026-03-01', '2026-03-02', '2026-03-03'])
            ->and(array_column($chart['datasets'], 'label'))->toBe(['Все', 'Опубликованные'])
            ->and($chart['datasets'][0]['values'])->toBe([0, 1, 0])
            ->and($content)->toContain('fastleo-chart.js');
    });
});

describe('a report with a failing and a hidden widget', function (): void {
    it('keeps the other widgets working and escapes the values', function (): void {
        $this->get(route('fastleo.pages.show', 'broken'))
            ->assertOk()
            ->assertSee('data-widget-failed', false)
            ->assertSee('Не удалось загрузить')
            ->assertSee('Работает')
            ->assertDontSee('Скрыт')
            ->assertDontSee('<b>x</b>', false)
            ->assertSee('&lt;b&gt;x&lt;/b&gt;', false)
            ->assertSee('1 234 567')
            ->assertSee('1,50')
            ->assertSee('2 500')
            ->assertDontSee('2 500,00')
            ->assertDontSee('не будет показано');
    });

    it('is closed to an admin without access', function (): void {
        $this->actingAs(admin(['email' => 'other@example.com']), Fastleo::GUARD);

        $this->get(route('fastleo.pages.show', 'broken'))->assertForbidden();
    });
});

describe('the filter values', function (): void {
    it('refuses to read a filter that the page does not have', function (): void {
        $filters = Filters::resolve([Period::make('period')], Request::create('/'));

        expect(fn (): PeriodValue => $filters->period('missing'))->toThrow(LogicException::class)
            ->and(fn (): ?string => $filters->get('missing'))->toThrow(LogicException::class)
            ->and(fn (): ?string => $filters->get('period'))->not->toThrow(LogicException::class);
    });

    it('rejects a filter name that is not an identifier', function (): void {
        Choice::make('a b');
    })->throws(InvalidArgumentException::class);

    it('cuts a row to the number of columns', function (): void {
        $rows = Rows::make('t')->columns(['a'])->rows(fn (): array => [['x', 'y']])->resolve();

        expect($rows['rows'][0])->toHaveCount(1);
    });
});
