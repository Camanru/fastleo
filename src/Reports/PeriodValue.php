<?php

declare(strict_types=1);

namespace Fastleo\Reports;

use Carbon\CarbonImmutable;
use Fastleo\Fastleo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Выбранный период отчёта: границы включительно, в часовом поясе админки (`fastleo.timezone`). Любая граница может
 * отсутствовать — период открыт.
 *
 * ```php
 * $period = $filters->period('period');
 * Lead::query()->tap(fn ($query) => $period->apply($query, 'created_at'))->count();
 * foreach ($period->days() as $day) { … } // '2026-03-01', '2026-03-02', …
 * ```
 */
final readonly class PeriodValue
{
    /** Самое большее дней в `days()`: график не должен разрастаться от опечатки в дате. */
    private const int MAX_DAYS = 366;

    /**
     * @param  CarbonImmutable|null  $from  начало первого дня или null
     * @param  CarbonImmutable|null  $to  конец последнего дня или null
     */
    public function __construct(public ?CarbonImmutable $from, public ?CarbonImmutable $to) {}

    /**
     * Ограничить запрос периодом по колонке даты со временем. Границы переводятся в часовой пояс сайта, в котором
     * хранится база.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, string $column): Builder
    {
        $zone = Fastleo::appTimezone();
        $qualified = $query->qualifyColumn($column);

        if ($this->from instanceof CarbonImmutable) {
            $query->where($qualified, '>=', $this->from->setTimezone($zone));
        }

        if ($this->to instanceof CarbonImmutable) {
            $query->where($qualified, '<=', $this->to->setTimezone($zone));
        }

        return $query;
    }

    /**
     * Даты периода `ГГГГ-ММ-ДД` по порядку (не больше года). Открытый период — пустой список.
     *
     * @return list<string>
     */
    public function days(): array
    {
        if (! $this->from instanceof CarbonImmutable || ! $this->to instanceof CarbonImmutable) {
            return [];
        }

        $days = [];

        for ($day = $this->from; $day->lte($this->to) && count($days) < self::MAX_DAYS; $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /**
     * Первая дата периода `ГГГГ-ММ-ДД` или null.
     */
    public function fromDate(): ?string
    {
        return $this->from?->toDateString();
    }

    /**
     * Последняя дата периода `ГГГГ-ММ-ДД` или null.
     */
    public function toDate(): ?string
    {
        return $this->to?->toDateString();
    }
}
