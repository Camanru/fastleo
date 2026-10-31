<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\ReportTiles;

use Fastleo\Page;
use Fastleo\Reports\Filters;
use Fastleo\Widgets\Stat;
use Override;

/**
 * Отчёт с шестью колонками: шесть плиток в ряд и одна во всю строку.
 */
final class TilesPage extends Page
{
    protected static int $columns = 6;

    /**
     * Шесть узких плиток и одна шире сетки.
     */
    #[Override]
    public function widgets(Filters $filters): array
    {
        return [
            ...array_map(fn (int $number): Stat => Stat::make('Плитка '.$number)->value($number), range(1, 6)),
            Stat::make('Во всю строку')->value(7)->columnSpan(6),
        ];
    }
}
