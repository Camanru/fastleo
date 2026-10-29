<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Policies\HiddenCategoryPolicy;
use Fastleo\Widgets\Chart;
use Fastleo\Widgets\Stat;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Article;
use Workbench\App\Models\Feedback;

beforeEach(function (): void {
    $this->me = admin(['name' => 'Анна']);
    $this->actingAs($this->me, Fastleo::GUARD);
});

describe('default dashboard', function (): void {
    it('shows the greeting, section tiles with counters and the server state', function (): void {
        useFixtureResources('Menu');
        $response = $this->get(route('fastleo.dashboard'));

        $response->assertOk()
            ->assertSee('Здравствуйте, Анна!')
            ->assertSee('data-section-tile', false)
            ->assertSee('Состояние сервера')
            ->assertSee('Очистить кеш');
    });

    it('keeps the demo dashboard of the site when it exists', function (): void {
        Article::factory()->count(3)->create(['is_published' => true]);

        $this->get(route('fastleo.dashboard'))
            ->assertOk()
            ->assertSee('Статей на сайте')
            ->assertSee('data-chart', false)
            ->assertSee('fastleo-chart.js', false);
    });
});

describe('widgets', function (): void {
    beforeEach(function (): void {
        useFixtureResources('Dashboard');
        Exceptions::fake();
    });

    it('shows a stat with its description, link and number format', function (): void {
        Article::factory()->count(2)->create();

        $this->get(route('fastleo.dashboard'))
            ->assertSee('Всего статей')
            ->assertSee('на сайте')
            ->assertSee('href="/fastleo/articles"', false)
            ->assertSee('1 234');
    });

    it('does not link a stat to a foreign scheme', function (): void {
        $this->get(route('fastleo.dashboard'))->assertDontSee('javascript:alert', false);
    });

    it('does not link a stat to another host', function (): void {
        $this->get(route('fastleo.dashboard'))->assertSee('Чужой хост')->assertSee('Обратная косая')->assertDontSee('evil.example', false);
    });

    it('shows a failed widget as an error and keeps the rest of the dashboard', function (): void {
        $this->get(route('fastleo.dashboard'))
            ->assertOk()
            ->assertSee('data-widget-failed', false)
            ->assertSee('Не удалось загрузить.')
            ->assertSee('Всего статей');

        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'boom');
    });

    it('hides widgets by the visible() callback', function (): void {
        $this->get(route('fastleo.dashboard'))->assertSee('Только владельцу')->assertDontSee('Скрытый');

        $this->actingAs(admin(), Fastleo::GUARD);
        $this->flushSession();
        $this->get(route('fastleo.dashboard'))->assertDontSee('Только владельцу');
    });

    it('draws a chart from the data and says so when there is no data', function (): void {
        Article::factory()->create();

        $html = $this->get(route('fastleo.dashboard'))->getContent();

        expect($html)->toContain('data-chart=')->toContain('Данных пока нет.')->toContain('fastleo-chart.js');
    });

    it('does not load the chart script when there is no chart', function (): void {
        useFixtureResources('Menu');

        $this->get(route('fastleo.dashboard'))->assertDontSee('fastleo-chart.js', false);
    });

    it('lists the last records of a section with a link to each', function (): void {
        $first = Feedback::factory()->create(['name' => 'Первый клиент']);
        Feedback::factory()->create(['name' => 'Второй клиент']);
        Feedback::factory()->create(['name' => 'Третий клиент']);

        $this->get(route('fastleo.dashboard'))
            ->assertSee('Третий клиент')
            ->assertSee('Второй клиент')
            ->assertDontSee('Первый клиент')
            ->assertSee(route('fastleo.resources.show', ['feedback', $first->id + 2]), false)
            ->assertSee('Все записи');
    });

    it('hides the table of a section the admin may not list', function (): void {
        Gate::policy(Feedback::class, HiddenCategoryPolicy::class);

        $this->get(route('fastleo.dashboard'))->assertOk()->assertDontSee('Последние обращения');
    });
});

describe('Chart::countByDay', function (): void {
    it('counts records per day with zeros for empty days', function (): void {
        Date::setTestNow('2026-10-29 12:00:00');
        Article::factory()->create(['created_at' => '2026-10-29 01:00:00']);
        Article::factory()->create(['created_at' => '2026-10-29 23:00:00']);
        Article::factory()->create(['created_at' => '2026-10-27 10:00:00']);
        Article::factory()->create(['created_at' => '2026-09-01 10:00:00']);

        $days = Chart::countByDay(Article::query(), 'created_at', 4);

        expect($days)->toBe(['26.10' => 0, '27.10' => 1, '28.10' => 0, '29.10' => 2]);
        Date::setTestNow();
    });

    it('accepts a model class and respects the scope of the query', function (): void {
        Article::factory()->count(2)->create(['is_published' => true]);
        Article::factory()->create(['is_published' => false]);

        expect(array_sum(Chart::countByDay(Article::class, 'created_at', 3)))->toBe(3)
            ->and(array_sum(Chart::countByDay(Article::query()->where('is_published', true), 'created_at', 3)))->toBe(2);
    });

    it('refuses an unsafe column name', function (): void {
        expect(fn (): array => Chart::countByDay(Article::query(), 'created_at) or 1=1 --'))->toThrow(InvalidArgumentException::class);
    });

    it('limits the number of days', function (): void {
        expect(Chart::countByDay(Article::query(), 'created_at', 100000))->toHaveCount(365);
    });
});

describe('Stat', function (): void {
    it('formats numbers and passes strings through', function (): void {
        $stat = Stat::make('Число')->value(1234567);
        $float = Stat::make('Дробь')->value(12.5);

        expect($stat->resolve()['value'])->toBe('1 234 567')
            ->and($float->resolve()['value'])->toBe('12,50')
            ->and(Stat::make('Текст')->value('42 шт.')->resolve()['value'])->toBe('42 шт.');
    });

    it('clamps the column span', function (): void {
        expect(Stat::make('a')->columnSpan(9)->getSpan())->toBe(4)
            ->and(Stat::make('a')->columnSpan(0)->getSpan())->toBe(1);
    });
});
