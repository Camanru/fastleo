<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Reports;

use Fastleo\Page;
use Fastleo\Reports\Choice;
use Fastleo\Reports\Filters;
use Fastleo\Reports\Period;
use Fastleo\Widgets\Chart;
use Fastleo\Widgets\Rows;
use Fastleo\Widgets\Stat;
use Illuminate\Database\Eloquent\Builder;
use Override;
use Workbench\App\Models\Article;

/**
 * Отчёт по статьям: период и статус сверху; число статей, график из двух линий и таблица по статусам с итогами.
 */
final class ArticlesPage extends Page
{
    /**
     * Период (по умолчанию — месяц) и статус.
     */
    #[Override]
    public function filters(): array
    {
        return [
            Period::make('period', 'Период')->default('month'),
            Choice::make('status', 'Статус')->options(['draft' => 'Черновик', 'published' => 'Опубликовано']),
        ];
    }

    /**
     * Виджеты по значениям фильтров.
     */
    #[Override]
    public function widgets(Filters $filters): array
    {
        $period = $filters->period('period');
        $status = $filters->get('status');
        $articles = fn (): Builder => $period->apply(Article::query(), 'created_at')->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status));

        return [
            Stat::make('Статей')->value(fn (): int => $articles()->count()),
            Chart::make('Динамика')->columnSpan(3)->series(fn (): array => [
                'Все' => collect($period->days())->mapWithKeys(fn (string $day): array => [$day => $articles()->whereDate('created_at', $day)->count()])->all(),
                'Опубликованные' => collect($period->days())->mapWithKeys(fn (string $day): array => [$day => $articles()->where('status', 'published')->whereDate('created_at', $day)->count()])->all(),
            ]),
            Rows::make('По статусам')
                ->columns(['Статус', 'Статей', 'Просмотров'])
                ->rows(fn (): array => $articles()->selectRaw('status, count(*) as total, sum(views) as views')->groupBy('status')->orderBy('status')->get()->map(fn (Article $row): array => [$row->status->label(), (int) $row->getAttribute('total'), (int) $row->getAttribute('views')])->all())
                ->footer(fn (): array => ['Итого', $articles()->count(), (int) $articles()->sum('views')])
                ->columnSpan(4),
        ];
    }
}
