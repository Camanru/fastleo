<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\ReportTiles;

use Fastleo\Page;
use Fastleo\Reports\Filters;
use Fastleo\Widgets\Stat;
use Override;

/**
 * Отчёт с обычной сеткой из четырёх колонок и виджетом, который просит шесть.
 */
final class WidePage extends Page
{
    /**
     * Виджет шире сетки.
     */
    #[Override]
    public function widgets(Filters $filters): array
    {
        return [Stat::make('Слишком широкая')->value(1)->columnSpan(6)];
    }
}
