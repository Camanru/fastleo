<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\FilterDefaults;

use Fastleo\Fields\Text;
use Fastleo\Filters\DateRangeFilter;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи, список которых открывается с периодом «вчера — сегодня»: умолчание считается при каждом показе.
 */
final class PeriodDefaultResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'period-default';

    /**
     * Заголовок записи.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Заголовок')->required()];
    }

    /**
     * Период с умолчанием-замыканием.
     */
    public function filters(): array
    {
        return [DateRangeFilter::make('created_at', 'Создано')->default(fn (): array => [
            'from' => now()->subDay()->toDateString(),
            'to' => now()->toDateString(),
        ])];
    }
}
