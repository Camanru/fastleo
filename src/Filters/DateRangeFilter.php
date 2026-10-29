<?php

declare(strict_types=1);

namespace Fastleo\Filters;

use Carbon\CarbonImmutable;
use Fastleo\Fastleo;
use Fastleo\Fields\Date;
use Fastleo\Fields\DateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Фильтр по датам «с … по …» (обе границы включительно, любую можно не указывать).
 *
 * Для колонки даты (`Date`, каст `'date'`) сравниваются даты. Для даты со временем (`DateTime`,
 * `created_at`) день считается в часовом поясе админки (`fastleo.timezone`).
 *
 * ```php
 * DateRangeFilter::make('published_on', 'Дата публикации');
 * DateRangeFilter::make('created_at', 'Создано');
 * ```
 */
class DateRangeFilter extends Filter
{
    /**
     * Границы `ГГГГ-ММ-ДД` или null, если нет ни одной допустимой.
     *
     * @return array{from: ?string, to: ?string}|null
     */
    #[Override]
    public function value(mixed $input): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        $from = $this->date($input['from'] ?? null);
        $to = $this->date($input['to'] ?? null);

        return $from === null && $to === null ? null : ['from' => $from, 'to' => $to];
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::filters.date-range';
    }

    /**
     * @param  Builder<Model>  $query
     */
    #[Override]
    protected function query(Builder $query, mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }

        $column = $query->qualifyColumn($this->name);
        $from = is_string($value['from'] ?? null) ? $value['from'] : null;
        $to = is_string($value['to'] ?? null) ? $value['to'] : null;

        if ($this->isDateOnly($query->getModel())) {
            if ($from !== null) {
                $query->whereDate($column, '>=', $from);
            }

            if ($to !== null) {
                $query->whereDate($column, '<=', $to);
            }

            return;
        }

        $timezone = Fastleo::appTimezone();

        if ($from !== null) {
            $query->where($column, '>=', CarbonImmutable::parse($from, Fastleo::timezone())->startOfDay()->setTimezone($timezone)->format('Y-m-d H:i:s'));
        }

        if ($to !== null) {
            $query->where($column, '<', CarbonImmutable::parse($to, Fastleo::timezone())->addDay()->startOfDay()->setTimezone($timezone)->format('Y-m-d H:i:s'));
        }
    }

    /**
     * Колонка хранит дату без времени.
     */
    private function isDateOnly(Model $model): bool
    {
        $field = $this->field();

        if ($field instanceof Date) {
            return ! $field instanceof DateTime;
        }

        return $model->hasCast($this->name, ['date', 'immutable_date']);
    }

    /**
     * Строка `ГГГГ-ММ-ДД` настоящей даты или null.
     */
    private function date(mixed $input): ?string
    {
        if (! is_string($input) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $input);

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $input ? $input : null;
    }
}
