<?php

declare(strict_types=1);

namespace Fastleo\Reports;

use Carbon\CarbonImmutable;
use Fastleo\Fastleo;
use Override;

/**
 * Период отчёта: даты «с … по …» и кнопки готовых периодов (сегодня, вчера, 7 дней, месяц, квартал). Дни считаются
 * в часовом поясе админки. Значение для отчёта — `PeriodValue` (`$filters->period('period')`).
 *
 * По умолчанию — сегодня; другое задаёт `default('month')` (имя готового периода) или замыкание, возвращающее
 * `['from' => 'ГГГГ-ММ-ДД', 'to' => 'ГГГГ-ММ-ДД']`. Если администратор стёр обе даты, период открыт (без границ).
 *
 * ```php
 * Period::make('period', 'Период')->default(fn () => ['from' => today()->subDay()->toDateString(), 'to' => today()->toDateString()]);
 * Period::make('period')->presets(['today', 'month'])->default('month');
 * ```
 */
final class Period extends ReportFilter
{
    /** Готовые периоды: имя → [начало, конец] относительно сегодняшнего дня. */
    public const array PRESETS = ['today', 'yesterday', 'week', 'month', 'quarter'];

    /** @var list<string> */
    private array $presets = self::PRESETS;

    /**
     * Какие кнопки готовых периодов показать (порядок сохраняется).
     *
     * @param  list<string>  $presets  `today`, `yesterday`, `week`, `month`, `quarter`
     */
    public function presets(array $presets): static
    {
        $this->presets = array_values(array_intersect($presets, self::PRESETS));

        return $this;
    }

    /**
     * Кнопки готовых периодов: имя → [начало, конец] (`ГГГГ-ММ-ДД`).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function presetRanges(): array
    {
        $ranges = [];

        foreach ($this->presets as $preset) {
            $ranges[$preset] = $this->preset($preset);
        }

        return $ranges;
    }

    #[Override]
    public function resolve(mixed $input, bool $given): PeriodValue
    {
        if ($given) {
            $range = is_array($input) ? [$this->date($input['from'] ?? null), $this->date($input['to'] ?? null)] : [null, null];
        } else {
            $default = $this->defaultValue();
            $range = match (true) {
                is_string($default) && in_array($default, self::PRESETS, true) => $this->preset($default),
                is_array($default) => [$this->date($default['from'] ?? null), $this->date($default['to'] ?? null)],
                default => $this->preset('today'),
            };
        }

        [$from, $to] = [$this->day($range[0] ?? null), $this->day($range[1] ?? null)];

        if ($from instanceof CarbonImmutable && $to instanceof CarbonImmutable && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return new PeriodValue($from?->startOfDay(), $to?->endOfDay());
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::reports.period';
    }

    /**
     * Границы готового периода.
     *
     * @return array{0: string, 1: string}
     */
    private function preset(string $preset): array
    {
        $today = CarbonImmutable::now(Fastleo::timezone())->startOfDay();

        $from = match ($preset) {
            'yesterday' => $today->subDay(),
            'week' => $today->subDays(6),
            'month' => $today->startOfMonth(),
            'quarter' => $today->startOfQuarter(),
            default => $today,
        };

        return [$from->toDateString(), ($preset === 'yesterday' ? $today->subDay() : $today)->toDateString()];
    }

    /**
     * Дата `ГГГГ-ММ-ДД` из адреса или null: строго, без «32 января».
     */
    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->toDateString() === $value ? $value : null;
    }

    /**
     * Начало дня в часовом поясе админки.
     */
    private function day(?string $date): ?CarbonImmutable
    {
        return ($date === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $date, Fastleo::timezone())) ?: null;
    }
}
