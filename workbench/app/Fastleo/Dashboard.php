<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Dashboard as BaseDashboard;
use Fastleo\Widgets\Chart;
use Fastleo\Widgets\Sections;
use Fastleo\Widgets\ServerStatus;
use Fastleo\Widgets\SoftNetica;
use Fastleo\Widgets\Stat;
use Fastleo\Widgets\Table;
use Override;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;
use Workbench\App\Models\Feedback;

/**
 * Демо-дашборд: числа, графики, последние обращения, плитки разделов и состояние сервера.
 */
final class Dashboard extends BaseDashboard
{
    /**
     * Виджеты главной страницы демо-сайта.
     */
    #[Override]
    public function widgets(): array
    {
        return [
            Stat::make('Статей на сайте')
                ->value(fn (): int => Article::query()->where('is_published', true)->count())
                ->description(fn (): string => 'всего '.Article::query()->count())
                ->icon('newspaper')
                ->url(route('fastleo.resources.index', 'articles')),
            Stat::make('Обращения')
                ->value(fn (): int => Feedback::query()->count())
                ->description(fn (): string => 'за неделю: '.Feedback::query()->where('created_at', '>=', now()->subWeek())->count())
                ->icon('inbox')
                ->url(route('fastleo.resources.index', 'feedback')),
            Chart::make('Статьи по дням')->line()->label('Статей')->data(fn (): array => Chart::countByDay(Article::query()))->columnSpan(2),
            Chart::make('Статьи по статусам')->bar()->data(fn (): array => collect(ArticleStatus::cases())
                ->mapWithKeys(fn (ArticleStatus $status): array => [$status->label() => Article::query()->where('status', $status)->count()])
                ->all())->columnSpan(2),
            Table::make('Последние обращения')->resource(FeedbackResource::class)->limit(5)->columnSpan(2),
            Sections::make()->columnSpan(2),
            ServerStatus::make()->columnSpan(4),
            SoftNetica::make()->columnSpan(2),
        ];
    }
}
