<?php

declare(strict_types=1);

namespace Fastleo\Reports;

use Illuminate\Http\Request;
use LogicException;

/**
 * Значения фильтров страницы-отчёта для одного запроса. Передаётся в `Page::widgets()`; виджеты берут значения
 * в замыканиях.
 *
 * ```php
 * public function widgets(Filters $filters): array
 * {
 *     $period = $filters->period('period');
 *     $manager = $filters->get('manager');   // ключ варианта или null («Все»)
 *
 *     return [Stat::make('Заявок')->value(fn () => Lead::query()->tap(fn ($q) => $period->apply($q, 'created_at'))->count())];
 * }
 * ```
 */
final readonly class Filters
{
    /**
     * @param  array<string, PeriodValue|string|null>  $values
     */
    private function __construct(private array $values) {}

    /**
     * Разобрать адрес `?filter[имя]=…` по описанию фильтров страницы.
     *
     * @param  list<ReportFilter>  $filters
     */
    public static function resolve(array $filters, Request $request): self
    {
        $input = $request->query('filter');
        $input = is_array($input) ? $input : [];
        $values = [];

        foreach ($filters as $filter) {
            $given = array_key_exists($filter->name, $input);
            $resolved = $filter->resolve($given ? $input[$filter->name] : null, $given);
            $values[$filter->name] = $resolved instanceof PeriodValue || is_string($resolved) ? $resolved : null;
        }

        return new self($values);
    }

    /**
     * Период: фильтр `Period` с этим именем.
     *
     * @throws LogicException фильтра нет или это не период
     */
    public function period(string $name): PeriodValue
    {
        $value = $this->values[$name] ?? null;

        return $value instanceof PeriodValue ? $value : throw new LogicException('Фильтр «'.$name.'» не описан как Period в filters() страницы.');
    }

    /**
     * Ключ выбранного варианта фильтра `Choice` или null («Все»).
     *
     * @throws LogicException фильтра с таким именем нет
     */
    public function get(string $name): ?string
    {
        if (! array_key_exists($name, $this->values)) {
            throw new LogicException('Фильтр «'.$name.'» не описан в filters() страницы.');
        }

        $value = $this->values[$name];

        return is_string($value) ? $value : null;
    }

    /**
     * Значение фильтра для панели: `PeriodValue`, ключ варианта или null.
     */
    public function value(string $name): PeriodValue|string|null
    {
        return $this->values[$name] ?? null;
    }
}
